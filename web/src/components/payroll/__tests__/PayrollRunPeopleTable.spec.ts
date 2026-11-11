import { ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollRunPeoplePage, PayrollRunPersonDetail, PayrollRunPersonRow } from '@/api/payroll'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: ref('cs-CZ'),
    t: (key: string, params?: unknown) => (typeof params === 'number' ? `${key}:${params}` : key),
    te: () => true,
  }),
}))

const m = vi.hoisted(() => ({
  runPeople: vi.fn(),
  runPerson: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { runPeople: m.runPeople, runPerson: m.runPerson },
}))

import PayrollRunPeopleTable from '@/components/payroll/PayrollRunPeopleTable.vue'

function person(overrides: Partial<PayrollRunPersonRow> = {}): PayrollRunPersonRow {
  return {
    employee_id: 31,
    full_name: 'Cyril Syntetický',
    result_status: 'calculated',
    gross_minor: 1_500_000,
    employee_social_minor: 106_500,
    employee_health_minor: 67_500,
    insurance_minor: 174_000,
    advance_tax_minor: 225_000,
    withholding_tax_minor: 0,
    tax_minor: 225_000,
    tax_bonus_minor: 0,
    net_minor: 1_101_000,
    payable_minor: 1_101_000,
    employments: [{ id: 7, code: 'D-007', relation_type: 'dpp' }],
    findings: { blockers: 0, warnings: 0 },
    has_finding: false,
    ...overrides,
  }
}

function page(overrides: Partial<PayrollRunPeoplePage> = {}): PayrollRunPeoplePage {
  const items = overrides.items ?? [person()]
  return {
    run_id: 15,
    period_start: '2026-04-01',
    revision_id: 9,
    revision_no: 1,
    revision_status: 'approved',
    items,
    total: items.length,
    limit: 25,
    offset: 0,
    sort: 'name',
    dir: 'asc',
    totals: {
      gross_minor: 1_500_000,
      insurance_minor: 174_000,
      tax_minor: 225_000,
      tax_bonus_minor: 0,
      net_minor: 1_101_000,
      payable_minor: 1_101_000,
    },
    ...overrides,
  }
}

function detail(): PayrollRunPersonDetail {
  return {
    run_id: 15,
    period_start: '2026-04-01',
    revision_id: 9,
    revision_no: 1,
    revision_status: 'approved',
    person: { employee_id: 31, full_name: 'Cyril Syntetický', employments: [] },
    result: { employee_id: 31, statutory: { person_reference: 'employee:31', status: 'calculated' } },
  }
}

const breakdownStub = (name: string) => ({
  name,
  props: ['people', 'personNames', 'revisionId', 'approved'],
  template: `<div data-stub="${name}">{{ people.length }}|{{ personNames[people[0]?.employee_id] }}|{{ revisionId }}|{{ approved }}</div>`,
})

function mountTable(props: Partial<{ approved: boolean, refreshKey: number }> = {}) {
  return mount(PayrollRunPeopleTable, {
    props: { runId: 15, revisionId: 9, period: '2026-04', approved: true, ...props },
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
        PayrollPayslipHourlyBreakdown: breakdownStub('hourly'),
        PayrollIncomeTaxBreakdown: breakdownStub('tax'),
        PayrollInsuranceBreakdown: breakdownStub('insurance'),
        PayrollNetPayBreakdown: breakdownStub('net'),
      },
    },
  })
}

describe('PayrollRunPeopleTable', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.runPeople.mockResolvedValue(page())
    m.runPerson.mockResolvedValue(detail())
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('loads the first page from the server and shows amounts, employment, finding and totals', async () => {
    m.runPeople.mockResolvedValue(page({
      items: [
        person(),
        person({
          employee_id: 32,
          full_name: 'Adam Syntetický',
          gross_minor: 360_100,
          insurance_minor: 0,
          advance_tax_minor: 0,
          withholding_tax_minor: 54_000,
          tax_minor: 54_000,
          net_minor: 306_100,
          payable_minor: 306_100,
          findings: { blockers: 0, warnings: 2 },
          has_finding: true,
        }),
        person({ employee_id: 33, full_name: 'Běla Syntetická', result_status: 'manual_review', net_minor: null, payable_minor: null }),
      ],
    }))

    const wrapper = mountTable()
    await flushPromises()

    expect(m.runPeople).toHaveBeenCalledTimes(1)
    expect(m.runPeople).toHaveBeenCalledWith(15, { limit: 25, offset: 0, q: '', findings: false })

    const row = wrapper.get('[data-testid="payroll-run-person-31"]')
    expect(row.text()).toContain('Cyril Syntetický')
    expect(row.text()).toContain('D-007 · payroll.people.relations.dpp')
    expect(row.text()).toMatch(/15\s000,00\sKč/)
    expect(row.text()).toMatch(/1\s740,00\sKč/)
    expect(row.text()).toContain('payroll.runs.people_table.tax_kind.advance')
    expect(row.text()).toMatch(/11\s010,00\sKč/)
    expect(wrapper.find('[data-testid="payroll-run-person-31-finding"]').exists()).toBe(false)

    // Srážková daň se od zálohy pozná popiskem pod částkou.
    expect(wrapper.get('[data-testid="payroll-run-person-32"]').text())
      .toContain('payroll.runs.people_table.tax_kind.withholding')
    expect(wrapper.get('[data-testid="payroll-run-person-32-finding"]').text())
      .toBe('payroll.runs.people_table.finding.warnings:2')
    // Osoba bez zákonného výsledku: částky, které nejsou, se nevymýšlejí.
    const review = wrapper.get('[data-testid="payroll-run-person-33"]')
    expect(wrapper.get('[data-testid="payroll-run-person-33-finding"]').text())
      .toBe('payroll.runs.people_table.finding.manual_review')
    expect(review.text()).toContain('—')

    expect(wrapper.get('[data-testid="payroll-run-people-totals"]').text()).toMatch(/15\s000,00\sKč/)
    expect(wrapper.get('[data-testid="payroll-run-people-count"]').text()).toBe('payroll.runs.people_table.count:3')
    // Stejné osoby i jako mobilní karty.
    expect(wrapper.findAll('[data-testid^="payroll-run-person-mobile-"]')).toHaveLength(3)
  })

  it('sorts on the server: amounts descending first, then ascending, then back to the default', async () => {
    const wrapper = mountTable()
    await flushPromises()

    await wrapper.get('[data-testid="payroll-run-people-sort-gross_minor"]').trigger('click')
    await flushPromises()
    expect(m.runPeople).toHaveBeenLastCalledWith(15, expect.objectContaining({ sort: 'gross_minor', dir: 'desc', offset: 0 }))

    await wrapper.get('[data-testid="payroll-run-people-sort-gross_minor"]').trigger('click')
    await flushPromises()
    expect(m.runPeople).toHaveBeenLastCalledWith(15, expect.objectContaining({ sort: 'gross_minor', dir: 'asc' }))

    await wrapper.get('[data-testid="payroll-run-people-sort-gross_minor"]').trigger('click')
    await flushPromises()
    expect(m.runPeople.mock.lastCall?.[1]).not.toHaveProperty('sort')

    await wrapper.get('[data-testid="payroll-run-people-sort-name"]').trigger('click')
    await flushPromises()
    expect(m.runPeople).toHaveBeenLastCalledWith(15, expect.objectContaining({ sort: 'name', dir: 'asc' }))
  })

  it('pages on the server and returns to the first page when the selection narrows', async () => {
    vi.useFakeTimers()
    m.runPeople.mockResolvedValue(page({ total: 60 }))
    const wrapper = mountTable()
    await flushPromises()

    const next = wrapper.findAll('[data-testid="payroll-run-15-people-pagination"] button')[1]!
    await next.trigger('click')
    await flushPromises()
    expect(m.runPeople).toHaveBeenLastCalledWith(15, expect.objectContaining({ offset: 25, limit: 25 }))

    // Hledání čeká na dopsání, pak jde na server a vrací se na první stránku.
    await wrapper.get('[data-testid="payroll-run-people-search"]').setValue('  dvoř ')
    expect(m.runPeople).toHaveBeenCalledTimes(2)
    vi.advanceTimersByTime(300)
    await flushPromises()
    expect(m.runPeople).toHaveBeenLastCalledWith(15, expect.objectContaining({ q: 'dvoř', offset: 0 }))

    await wrapper.get('[data-testid="payroll-run-people-findings"]').setValue(true)
    await flushPromises()
    expect(m.runPeople).toHaveBeenLastCalledWith(15, expect.objectContaining({ q: 'dvoř', findings: true, offset: 0 }))
  })

  it('says that the search found nobody instead of claiming the run is empty', async () => {
    vi.useFakeTimers()
    const wrapper = mountTable()
    await flushPromises()

    m.runPeople.mockResolvedValue(page({ items: [], total: 0 }))
    await wrapper.get('[data-testid="payroll-run-people-search"]').setValue('nikdo')
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(wrapper.get('[data-testid="payroll-run-people-empty"]').text()).toBe('payroll.runs.people_table.no_results')
  })

  it('downloads one person on demand and hands it to the existing breakdowns', async () => {
    const wrapper = mountTable()
    await flushPromises()
    expect(m.runPerson).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="payroll-run-15-person-detail"]').exists()).toBe(false)

    await wrapper.get('[data-testid="payroll-run-person-31-detail"]').trigger('click')
    await flushPromises()

    expect(m.runPerson).toHaveBeenCalledWith(15, 31)
    const panel = wrapper.get('[data-testid="payroll-run-15-person-detail"]')
    for (const name of ['hourly', 'tax', 'insurance', 'net']) {
      expect(panel.get(`[data-stub="${name}"]`).text()).toContain('1|Cyril Syntetický')
    }
    expect(panel.get('[data-stub="insurance"]').text()).toBe('1|Cyril Syntetický|9|')
    expect(panel.get('[data-stub="net"]').text()).toBe('1|Cyril Syntetický|9|true')

    await wrapper.get('[data-testid="payroll-run-person-31-detail"]').trigger('click')
    expect(wrapper.find('[data-testid="payroll-run-15-person-detail"]').exists()).toBe(false)
  })

  it('links to the payslip of the person only once the run is approved', async () => {
    const approved = mountTable()
    await flushPromises()
    const link = approved.get('[data-testid="payroll-run-person-31-payslip"]')
    expect(JSON.parse(link.attributes('data-to')!)).toEqual({
      name: 'payroll-documents',
      query: { period: '2026-04', tab: 'monthly', person: '31' },
    })

    const calculated = mountTable({ approved: false })
    await flushPromises()
    expect(calculated.find('[data-testid="payroll-run-person-31-payslip"]').exists()).toBe(false)
  })

  it('reloads after a recalculation of the same revision and closes the stale detail', async () => {
    const wrapper = mountTable({ refreshKey: 3 })
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-person-31-detail"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="payroll-run-15-person-detail"]').exists()).toBe(true)

    await wrapper.setProps({ refreshKey: 4 })
    await flushPromises()

    expect(m.runPeople).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="payroll-run-15-person-detail"]').exists()).toBe(false)
  })

  it('reports a failed load with a retry instead of an empty table', async () => {
    m.runPeople.mockRejectedValueOnce(new Error('network'))
    const wrapper = mountTable()
    await flushPromises()

    const failed = wrapper.get('[data-testid="payroll-run-people-failed"]')
    expect(failed.text()).toContain('payroll.runs.breakdown_failed')
    expect(wrapper.find('[data-testid="payroll-run-people-empty"]').exists()).toBe(false)

    await failed.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="payroll-run-person-31"]').exists()).toBe(true)
  })

  it('reports a failed detail load and lets the user retry', async () => {
    m.runPerson.mockRejectedValueOnce(new Error('network'))
    const wrapper = mountTable()
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-person-31-detail"]').trigger('click')
    await flushPromises()

    const failed = wrapper.get('[data-testid="payroll-run-person-detail-failed"]')
    await failed.get('button').trigger('click')
    await flushPromises()

    expect(m.runPerson).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-stub="tax"]').exists()).toBe(true)
  })
})
