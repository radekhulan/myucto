import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type {
  PayrollJmhzPvpojPreview,
  PayrollSubmissionOverviewItem,
} from '@/api/payroll'

const m = vi.hoisted(() => ({
  freezePreparation: vi.fn(),
  freezeSubmission: vi.fn(),
  sendTransport: vi.fn(),
  enqueueIsds: vi.fn(),
  gatewayStart: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    freezeJmhzPreparation: m.freezePreparation,
    freezeJmhzSubmission: m.freezeSubmission,
    sendJmhzTransport: m.sendTransport,
    enqueueJmhzIsds: m.enqueueIsds,
  },
}))
vi.mock('@/api/dataBox', () => ({
  dataBoxApi: { gatewayStartPayroll: m.gatewayStart },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) =>
    params ? `${key}:${JSON.stringify(params)}` : key }),
}))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (error: unknown, fallback = '') =>
    error instanceof Error ? error.message : fallback,
}))

import PayrollJmhzDispatchPanel from '../PayrollJmhzDispatchPanel.vue'

const preview = {
  run_id: 42,
  revision_id: 7,
  revision_no: 1,
  period: '2026-07',
  office: {
    office_id: 3,
    code: 'PRAHA',
    name: 'Praha',
    variable_symbol: '12345678',
  },
} as unknown as PayrollJmhzPvpojPreview

function obligation(overrides: Partial<PayrollSubmissionOverviewItem> = {}) {
  return {
    id: 99,
    environment: 'production',
    agenda_code: 'JMHZ25',
    agenda_group: 'jmhz',
    subject_type: 'payroll_run',
    subject_reference: 'payroll_run:42:office:3',
    period_start: '2026-07-01',
    period_end: '2026-07-31',
    obligation_kind: 'regular',
    preferred_channel: 'vrep_apep',
    status: 'open',
    row_version: 1,
    earliest_submission_on: '2026-08-01',
    due_on: '2026-08-20',
    calendar_basis: 'cssz',
    deadline: {
      phase: 'open',
      days_to_due: 10,
      is_action_required: false,
      is_overdue: false,
    },
    latest_submission: null,
    ...overrides,
  } as PayrollSubmissionOverviewItem
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.stubGlobal('crypto', { randomUUID: vi.fn(() => '00000000-0000-4000-8000-000000000001') })
  m.freezePreparation.mockResolvedValue({ id: 55 })
  m.freezeSubmission.mockResolvedValue({ submission_id: 66, status: 'ready' })
  m.enqueueIsds.mockResolvedValue({
    outbox_id: 77,
    created: true,
    environment: 'production',
    recipient: { environment: 'production', box_id: '5ffu6xk', name: 'ČSSZ', note: '' },
    subject: 'JMHZ 2026-07',
    sender_ident: 'MU-JMHZ',
    attachment: { filename: 'jmhz.xml', mime: 'application/xml', sha256: 'a', bytes: 10 },
    transport: { automatic: true, channel: 'gateway', reason: null },
    response_hint: { subject_prefix: 'JMHZ', attachment_prefix: 'JMHZ', note: '' },
  })
  m.gatewayStart.mockResolvedValue({
    session_id: 1,
    app_token: 'token',
    redirect_url: 'https://www.datovka.gov.cz/as/login',
    login_guidance: 'Přihlaste se metodou, kterou nabízí ISDS.',
    login_policy_documented: false,
    expires_at: '2026-08-25 15:00:00',
    resumed: false,
  })
})

describe('PayrollJmhzDispatchPanel', () => {
  it('zmrazí JMHZ, připraví ISDS zprávu a přesměruje až po potvrzení', async () => {
    const assign = vi.fn()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { assign },
    })
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'production',
        previews: [preview],
        obligations: [obligation()],
      },
    })

    await wrapper.get('[data-test="jmhz-dispatch-isds-7:3"]').trigger('click')
    await flushPromises()

    expect(m.freezePreparation).toHaveBeenCalledWith(7, expect.any(String), 'production')
    expect(m.freezeSubmission).toHaveBeenCalledWith(55, 99, 'production', 3)
    expect(m.enqueueIsds).toHaveBeenCalledWith(66, 'production')
    expect(m.gatewayStart).toHaveBeenCalledWith(77)
    expect(assign).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="jmhz-dispatch-gateway"]').text()).toContain(
      'Přihlaste se metodou, kterou nabízí ISDS.',
    )

    const continueButton = wrapper.findAll('button').find(button =>
      button.text().includes('jmhz_gateway_continue'),
    )
    await continueButton!.trigger('click')
    expect(assign).toHaveBeenCalledWith('https://www.datovka.gov.cz/as/login')
  })

  it('před otevřením ostré lhůty odeslání nenabídne', async () => {
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'production',
        previews: [preview],
        obligations: [obligation({ deadline: {
          phase: 'not_open',
          days_to_due: 25,
          is_action_required: false,
          is_overdue: false,
        } })],
      },
    })

    const button = wrapper.get('[data-test="jmhz-dispatch-isds-7:3"]')
    expect((button.element as HTMLButtonElement).disabled).toBe(true)
    expect(wrapper.text()).toContain('jmhz_dispatch_not_open')
    expect(m.freezePreparation).not.toHaveBeenCalled()
  })

  it('při prvním podání založí povinnost automaticky', async () => {
    m.sendTransport.mockResolvedValue({
      attempt: { id: 77, status: 'submitted' },
      acknowledgement: null,
      settled: false,
      report: null,
    })
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'test',
        previews: [preview],
        obligations: [],
      },
    })

    const button = wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]')
    expect((button.element as HTMLButtonElement).disabled).toBe(false)
    await button.trigger('click')
    await flushPromises()

    expect(m.freezePreparation).toHaveBeenCalledWith(7, expect.any(String), 'test')
    expect(m.freezeSubmission).toHaveBeenCalledWith(55, null, 'test', 3)
    expect(m.sendTransport).toHaveBeenCalledWith(
      66,
      '12345678',
      'test',
      expect.any(String),
    )
  })

  it('znovu použije existující zmrazené podání ve stavu ready', async () => {
    m.enqueueIsds.mockResolvedValue({
      ...(await m.enqueueIsds()),
      transport: { automatic: false, channel: 'manual_upload', reason: 'gateway_unavailable' },
    })
    m.enqueueIsds.mockClear()
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'test',
        previews: [preview],
        obligations: [obligation({
          environment: 'test',
          latest_submission: {
            id: 88,
            status: 'ready',
            submission_kind: 'regular',
            channel: 'vrep_apep',
            submitted_at: null,
            decided_at: null,
          },
        })],
      },
    })

    await wrapper.get('[data-test="jmhz-dispatch-isds-7:3"]').trigger('click')
    await flushPromises()

    expect(m.freezePreparation).not.toHaveBeenCalled()
    expect(m.freezeSubmission).not.toHaveBeenCalled()
    expect(m.enqueueIsds).toHaveBeenCalledWith(88, 'test')
  })
})
