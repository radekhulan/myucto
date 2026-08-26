import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
  prepare: vi.fn(),
  send: vi.fn(),
  status: vi.fn(),
  poll: vi.fn(),
  close: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    previewEmploymentRegistration: m.preview,
    prepareEmploymentRegistration: m.prepare,
    sendEmploymentRegistrationTransport: m.send,
    employmentRegistrationTransportStatus: m.status,
    pollEmploymentRegistrationTransportAttempt: m.poll,
    closeEmploymentRegistrationTransportAttempt: m.close,
  },
}))

vi.mock('@/api/errors', () => ({
  apiErrorMessage: (exception: unknown, fallback: string) =>
    (exception as { message?: string }).message ?? fallback,
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    locale: { value: 'cs' },
  }),
}))

import EmploymentRegistrationPanel from '@/pages/payroll/EmploymentRegistrationPanel.vue'

const deadline = {
  earliest_registration_on: '2026-08-14',
  due_on: '2026-08-22',
  calendar_basis: 'calendar_days',
  ruleset_id: 'cz-employee-registration-2026-07.v1',
}

const preview = {
  employment_id: 5,
  agenda_code: 'PREZEC26',
  interaction: 'limited_pre_registration',
  action_code: 9,
  xml: '<PREZEC/>',
  xml_sha256: 'a'.repeat(64),
  deadline,
  employer_registration: null,
  official_submission: { supported: false, reason: 'Test.' },
}

function mountPanel(canWrite = true) {
  return mount(EmploymentRegistrationPanel, {
    props: { employmentId: 5, canWrite },
  })
}

describe('EmploymentRegistrationPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.stubGlobal('crypto', {
      randomUUID: vi.fn(() => '00000000-0000-4000-8000-000000000001'),
    })
    m.preview.mockResolvedValue(preview)
    m.status.mockResolvedValue({
      agenda_code: 'PREZEC26',
      submission_class: 'CSSZ_PREZEC',
      attempt: null,
    })
  })

  it('shows the deadline window and which form will be filed', async () => {
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    const window = wrapper.find('[data-test="registration-deadline"]')
    expect(window.exists()).toBe(true)
    expect(window.text()).toContain('registration.agenda.PREZEC26')
    expect(window.text()).toContain('registration.interaction.limited_pre_registration')
  })

  it('never claims the employee is registered once the filing is prepared', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 12,
      obligation_id: 3,
      part_id: 4,
      artifact_id: 6,
      status: 'ready',
      row_version: 3,
      environment: 'test',
      agenda_code: 'PREZEC26',
      interaction: 'limited_pre_registration',
      artifact_sha256: 'b'.repeat(64),
      created: true,
      deadline,
    })
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    const prepared = wrapper.find('[data-test="registration-prepared"]')
    expect(prepared.exists()).toBe(true)
    // „Odesláno != přijato" musí být vidět i v UI.
    expect(prepared.text()).toContain('registration.not_sent_yet')
  })

  it('surfaces the server message naming the missing field', async () => {
    m.preview.mockRejectedValue({
      message: 'Účtárna nemá vyplněný variabilní symbol ČSSZ.',
    })
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="registration-error"]').text())
      .toContain('variabilní symbol')
  })

  it('warns about the employer deadline for the first employee', async () => {
    m.preview.mockResolvedValue({
      ...preview,
      employer_registration: {
        earliest_registration_on: '2026-08-07',
        due_on: '2026-08-20',
        deemed_employer_from: '2026-08-07',
        no_show_notification_due_on: '2026-08-30',
        calendar_basis: 'czech_working_days',
        ruleset_id: 'cz-jmhz-employer-registration-2026-07.v1',
      },
    })
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="registration-employer-deadline"]').exists())
      .toBe(true)
  })

  it('keeps the filing action disabled without write permission', async () => {
    const wrapper = mountPanel(false)
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(
      wrapper.find('[data-test="registration-prepare"]').attributes('disabled'),
    ).toBeDefined()
  })

  it('sends, fetches the result and closes only after explicit clicks', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 12,
      obligation_id: 3,
      part_id: 4,
      artifact_id: 6,
      status: 'ready',
      row_version: 3,
      environment: 'test',
      agenda_code: 'PREZEC26',
      interaction: 'limited_pre_registration',
      artifact_sha256: 'b'.repeat(64),
      created: true,
      deadline,
    })
    m.send.mockResolvedValue({
      agenda_code: 'PREZEC26',
      submission_class: 'CSSZ_PREZEC',
      payload_sha256: 'b'.repeat(64),
      acknowledgement: { correlation_id: 'CID-1', poll_interval_seconds: 30, gateway_timestamp: null },
      settled: false,
      attempt: { id: 87, status: 'awaiting_protocol', closed_at: null },
    })
    m.poll.mockResolvedValue({
      acknowledgement: null,
      settled: true,
      report: { status: 'ProcessedAndComplete', errors: [] },
      attempt: { id: 87, status: 'completed', closed_at: null },
    })
    m.close.mockResolvedValue({
      closed: true,
      already_closed: false,
      attempt: { id: 87, status: 'completed', closed_at: '2026-08-26 12:00:00' },
    })

    const wrapper = mountPanel()
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    const actions = wrapper.get('[data-test="registration-transport-actions"]')
    await actions.get('button').trigger('click')
    await flushPromises()

    expect(m.send).toHaveBeenCalledWith(
      12,
      'test',
      '00000000-0000-4000-8000-000000000001',
    )
    expect(m.poll).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="registration-transport-result"]').text())
      .toContain('registration.awaiting_protocol')

    await actions.get('button').trigger('click')
    await flushPromises()
    expect(m.poll).toHaveBeenCalledWith(87, 'test')
    expect(m.close).not.toHaveBeenCalled()

    await actions.get('button').trigger('click')
    await flushPromises()
    expect(m.close).toHaveBeenCalledWith(87, 'test')
    expect(wrapper.get('[data-test="registration-transport-result"]').text())
      .toContain('registration.closed')
  })

  it('uses the selected production environment throughout the manual flow', async () => {
    const wrapper = mountPanel()
    await wrapper.get('[data-test="registration-environment"]').setValue('production')
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(m.preview).toHaveBeenCalledWith(5, 'production')
  })

  it('after reload resumes the stored attempt without sending it again', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 12,
      obligation_id: 3,
      part_id: 4,
      artifact_id: 6,
      status: 'submitted',
      row_version: 4,
      environment: 'test',
      agenda_code: 'PREZEC26',
      interaction: 'limited_pre_registration',
      artifact_sha256: 'b'.repeat(64),
      created: false,
      deadline,
    })
    m.status.mockResolvedValue({
      agenda_code: 'PREZEC26',
      submission_class: 'CSSZ_PREZEC',
      attempt: { id: 87, status: 'awaiting_protocol', closed_at: null },
    })
    const wrapper = mountPanel()
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    expect(m.status).toHaveBeenCalledWith(12, 'test')
    expect(m.send).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="registration-transport-actions"]').text())
      .toContain('registration.poll')
  })
})
