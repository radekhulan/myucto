import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollMonthGuideResponse, PayrollMonthGuideStep } from '@/api/payroll'

const m = vi.hoisted(() => ({
  monthGuide: vi.fn(),
  replace: vi.fn(),
  query: { period: '2026-10' } as Record<string, string>,
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { monthGuide: m.monthGuide },
}))

vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.query }),
  useRouter: () => ({ replace: m.replace }),
}))

vi.mock('@/composables/useWindowRefresh', () => ({
  useWindowRefresh: () => undefined,
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    te: (key: string) => key.includes('.code.time_') || key.endsWith('.run.waiting') || key.endsWith('.submissions.waiting'),
    locale: { value: 'cs' },
  }),
}))

import PayrollMonthGuide from '@/pages/payroll/PayrollMonthGuide.vue'

function step(key: PayrollMonthGuideStep['key'], overrides: Partial<PayrollMonthGuideStep> = {}): PayrollMonthGuideStep {
  return { key, state: 'done', remaining: 0, action: null, facts: {}, ...overrides }
}

function response(overrides: Partial<PayrollMonthGuideResponse> = {}): PayrollMonthGuideResponse {
  return {
    period: '2026-10',
    current_step: 'inputs',
    runs: [],
    steps: [
      step('inputs', {
        state: 'todo',
        remaining: 42,
        action: 'attendance_import',
        facts: {
          codes: [{ code: 'time_month_not_approved', count: 40 }, { code: 'draft_inputs_present', count: 2 }],
          stopping: 42,
          attendance_batches: { count: 0, persons: 0 },
        },
      }),
      step('people', { action: 'people', facts: { codes: [], stopping: 0 } }),
      step('registrations', {
        state: 'todo',
        remaining: 3,
        action: 'registration_a1',
        facts: {
          registration_a1: { total: 2, preparable: 2, incomplete: 0, overdue: 1, too_early: 0, from: '2026-10-01', to: '2026-10-31' },
          hoz_open: 1,
          unprepared_overdue: 2,
        },
      }),
      step('check', {
        state: 'todo',
        remaining: 2,
        action: 'runs',
        facts: {
          source: 'readiness',
          by_impact: { blocking: 0, approval: 2, revision: 1, anytime: 0 },
          bulk_actions: [{ kind: 'health_insurer', count: 3, employee_ids: [7, 8] }],
          findings: [{ code: 'time_month_not_approved', impact: 'approval', message: 'Docházka není schválená.', count: 40 }],
        },
      }),
      step('run', { state: 'todo', action: 'create_run', facts: { status: null } }),
      step('submissions', {
        state: 'waiting',
        remaining: 4,
        action: 'monthly_checklist',
        facts: { summary: { total: 4, send: 1, generate: 3, manual: 0, await: 0, done: 0 }, jmhz: { total: 1, done: 0 } },
      }),
      step('payments', { state: 'unavailable', facts: null }),
    ],
    ...overrides,
  }
}

function mountGuide() {
  return mount(PayrollMonthGuide, {
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  })
}

function to(wrapper: ReturnType<typeof mountGuide>, selector: string) {
  return JSON.parse(wrapper.get(selector).attributes('data-to') ?? 'null')
}

describe('PayrollMonthGuide', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.query = { period: '2026-10' }
    m.monthGuide.mockResolvedValue(response())
  })

  it('loads the guide for the period from the address and shows step states', async () => {
    const wrapper = mountGuide()
    await flushPromises()

    expect(m.monthGuide).toHaveBeenCalledWith('2026-10', 'production')
    expect(wrapper.findAll('[data-test^="month-guide-step-"]')).toHaveLength(7)
    expect(wrapper.get('[data-test="month-guide-step-inputs"]').attributes('data-state')).toBe('todo')
    expect(wrapper.get('[data-test="month-guide-state-inputs"]').text()).toContain('"count":42')
    expect(wrapper.get('[data-test="month-guide-state-people"]').text()).toBe('payroll.month_guide.state.done')
    expect(wrapper.get('[data-test="month-guide-state-payments"]').text()).toBe('payroll.month_guide.state.unavailable')
    expect(wrapper.get('[data-test="month-guide-progress"]').text()).toContain('"done":1,"total":6')
    expect(wrapper.get('[data-test="month-guide-codes-inputs"]').text()).toContain('payroll.month_guide.code.time_month_not_approved')
    expect(wrapper.get('[data-test="month-guide-codes-inputs"]').text()).toContain('payroll.month_guide.code.other')
  })

  it('points each primary action to the existing screen for the period', async () => {
    const wrapper = mountGuide()
    await flushPromises()

    expect(to(wrapper, '[data-test="month-guide-action-inputs"]')).toEqual({ name: 'payroll-imports', query: { tab: 'attendance' } })
    expect(to(wrapper, '[data-test="month-guide-action-registrations"]')).toEqual({
      name: 'payroll-submissions-tab',
      params: { tab: 'registration_a1' },
      query: { period: '2026-10', environment: 'production' },
    })
    expect(to(wrapper, '[data-test="month-guide-action-check"]')).toEqual({ name: 'payroll-runs', query: { period: '2026-10' } })
    expect(to(wrapper, '[data-test="month-guide-action-run"]')).toEqual({ name: 'payroll-runs', query: { period: '2026-10' } })
    expect(to(wrapper, '[data-test="month-guide-action-submissions"]')).toEqual({
      name: 'payroll-submissions-tab',
      params: { tab: 'monthly' },
      query: { period: '2026-10', environment: 'production' },
    })
    expect(wrapper.find('[data-test="month-guide-action-payments"]').exists()).toBe(false)
  })

  it('offers bulk fixes and the filed-outside-app shortcut as secondary links', async () => {
    const wrapper = mountGuide()
    await flushPromises()

    expect(to(wrapper, '[data-test="month-guide-link-check-bulk-health_insurer"]')).toEqual({
      name: 'payroll-people',
      query: { bulk: 'health_insurer', period: '2026-10', employees: '7,8' },
    })
    expect(to(wrapper, '[data-test="month-guide-link-registrations-external"]')).toEqual({ name: 'payroll-dashboard', hash: '#payroll-deadlines' })
    expect(wrapper.get('[data-test="month-guide-link-registrations-external"]').text()).toContain('"count":2')
    expect(to(wrapper, '[data-test="month-guide-link-submissions-jmhz"]')).toMatchObject({ params: { tab: 'jmhz' } })
    expect(wrapper.find('[data-test="month-guide-link-inputs-attendance_import"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.month_guide.steps.submissions.waiting')
  })

  it('reloads on period change and keeps the period in the address', async () => {
    const wrapper = mountGuide()
    await flushPromises()

    await wrapper.get('[data-test="month-guide-period"]').setValue('2026-09')
    await wrapper.get('[data-test="month-guide-period"]').trigger('change')
    await flushPromises()

    expect(m.replace).toHaveBeenCalledWith({ query: { period: '2026-09' } })
    expect(m.monthGuide).toHaveBeenLastCalledWith('2026-09', 'production')
  })

  it('shows the error with a retry button when loading fails', async () => {
    m.monthGuide.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mountGuide()
    await flushPromises()

    expect(wrapper.find('[data-test="month-guide-error"]').exists()).toBe(true)
    m.monthGuide.mockResolvedValueOnce(response({ current_step: null, steps: response().steps.map(s => ({ ...s, state: 'done' as const })) }))
    await wrapper.get('[data-test="month-guide-error"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="month-guide-all-done"]').exists()).toBe(true)
  })
})
