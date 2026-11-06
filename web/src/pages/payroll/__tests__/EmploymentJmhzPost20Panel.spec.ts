import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollJmhzPost20Guide } from '@/api/payroll'

const m = vi.hoisted(() => ({
  jmhzPost20Guide: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { jmhzPost20Guide: m.jmhzPost20Guide },
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

vi.mock('vue-router', () => ({ RouterLink: { props: ['to'], template: '<a :href="JSON.stringify(to)"><slot /></a>' } }))

import EmploymentJmhzPost20Panel from '@/pages/payroll/EmploymentJmhzPost20Panel.vue'

function guide(overrides: Partial<PayrollJmhzPost20Guide> = {}): PayrollJmhzPost20Guide {
  return {
    environment: 'production',
    employment_id: 12,
    situation: 'late_termination',
    employment_start: '2026-01-01',
    employment_end: '2026-06-30',
    regzec_actions: [2],
    registration: { events: [], prepared: false, submitted: false },
    months: [{
      period: '2026-07',
      submission_id: 41,
      submission_status: 'accepted',
      form_guid: '01A18238-0000-7000-8000-0000000000F1',
      scope: 'outside',
      action: 'annul',
      annullable: true,
      blocker: null,
      amendments: [],
    }],
    status: 'open',
    order: 'amendments_first',
    lookback_months: 36,
    warnings: [],
    ...overrides,
  }
}

describe('EmploymentJmhzPost20Panel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('loads the guide only after the situation is chosen explicitly', async () => {
    m.jmhzPost20Guide.mockResolvedValue(guide())
    const wrapper = mount(EmploymentJmhzPost20Panel, { props: { employmentId: 12 } })

    expect(wrapper.find('[data-test="post20-load"]').attributes('disabled')).toBeDefined()
    await wrapper.find('[data-test="post20-situation"]').setValue('late_termination')
    await wrapper.find('[data-test="post20-load"]').trigger('click')
    await flushPromises()

    expect(m.jmhzPost20Guide).toHaveBeenCalledWith(12, 'late_termination', 'production')
    expect(wrapper.find('[data-test="post20-status"]').text()).toBe('payroll.people.post20.status.open')
    expect(wrapper.find('[data-test="post20-registration-missing"]').exists()).toBe(true)
    const month = wrapper.find('[data-test="post20-month-2026-07"]')
    expect(month.text()).toContain('payroll.people.post20.actions.annul')
    expect(month.find('a').attributes('href')).toContain('"tab":"transport"')
  })

  it('shows a filed registration and an amended month without a link', async () => {
    m.jmhzPost20Guide.mockResolvedValue(guide({
      status: 'complete',
      registration: {
        events: [{ id: 5, action_code: 2, effective_on: '2026-06-30', submitted: true }],
        prepared: true,
        submitted: true,
      },
      months: [{
        ...guide().months[0],
        action: 'amended',
        annullable: false,
        amendments: [{ submission_id: 77, status: 'ready', submission_kind: 'correction' }],
      }],
    }))
    const wrapper = mount(EmploymentJmhzPost20Panel, { props: { employmentId: 12 } })
    await wrapper.find('[data-test="post20-situation"]').setValue('late_termination')
    await wrapper.find('[data-test="post20-load"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="post20-status"]').text()).toBe('payroll.people.post20.status.complete')
    expect(wrapper.text()).toContain('payroll.people.post20.registration_submitted')
    const month = wrapper.find('[data-test="post20-month-2026-07"]')
    expect(month.text()).toContain('payroll.people.post20.actions.amended')
    expect(month.find('a').exists()).toBe(false)
  })

  it('offers both variants for a form that carried income', async () => {
    m.jmhzPost20Guide.mockResolvedValue(guide({
      months: [{
        ...guide().months[0],
        action: 'choose_variant',
        annullable: false,
        blocker: { code: 'jmhz_zero_amendment_original_not_zero', message: 'příjem' },
        variants: [
          { key: 'returned', route: 'content_correction', supported: true },
          { key: 'not_returned', route: 'manual_deferred_income', supported: false },
        ],
      }],
    }))
    const wrapper = mount(EmploymentJmhzPost20Panel, { props: { employmentId: 12 } })
    await wrapper.find('[data-test="post20-situation"]').setValue('late_termination')
    await wrapper.find('[data-test="post20-load"]').trigger('click')
    await flushPromises()

    const month = wrapper.find('[data-test="post20-month-2026-07"]')
    expect(month.text()).toContain('payroll.people.post20.actions.choose_variant')
    const variants = wrapper.find('[data-test="post20-variants-2026-07"]')
    expect(variants.text()).toContain('payroll.people.post20.variants.returned')
    expect(variants.text()).toContain('payroll.people.post20.variants.not_returned')
    expect(month.find('a').attributes('href')).toContain('"tab":"transport"')
  })
})
