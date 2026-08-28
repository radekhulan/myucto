import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
  prepare: vi.fn(),
  send: vi.fn(),
  status: vi.fn(),
  poll: vi.fn(),
  close: vi.fn(),
  events: vi.fn(),
  approveEvent: vi.fn(),
  a1Profile: vi.fn(),
  saveA1Profile: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    previewEmploymentRegistration: m.preview,
    prepareEmploymentRegistration: m.prepare,
    sendEmploymentRegistrationTransport: m.send,
    employmentRegistrationTransportStatus: m.status,
    pollEmploymentRegistrationTransportAttempt: m.poll,
    closeEmploymentRegistrationTransportAttempt: m.close,
    employmentRegistrationEvents: m.events,
    approveEmploymentRegistrationEvent: m.approveEvent,
    employmentRegistrationA1Profile: m.a1Profile,
    saveEmploymentRegistrationA1Profile: m.saveA1Profile,
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
    m.events.mockResolvedValue([])
    m.a1Profile.mockResolvedValue(null)
  })

  it('saves the authoritative A1 profile before preview and prepare', async () => {
    m.saveA1Profile.mockResolvedValue({
      effective_on: '2026-08-14',
      row_version: 1,
      reference_hash: 'a'.repeat(64),
      created_at: '2026-08-14 10:00:00',
      created: true,
      permanent_address: {},
    })
    m.preview.mockResolvedValue({
      ...preview,
      agenda_code: 'REGZEC25',
      interaction: 'hire',
      action_code: 1,
    })
    m.prepare.mockResolvedValue({
      submission_id: 14,
      obligation_id: 15,
      part_id: 16,
      artifact_id: 17,
      status: 'ready',
      row_version: 1,
      environment: 'test',
      agenda_code: 'REGZEC25',
      interaction: 'hire',
      artifact_sha256: 'c'.repeat(64),
      created: true,
      deadline,
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    const input = wrapper.get('[data-test="registration-a1-json"]')
    await input.setValue(JSON.stringify({
      effective_on: '2026-08-14',
      row_version: 0,
      permanent_address: {},
    }))
    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(m.saveA1Profile).toHaveBeenCalledWith(5, expect.objectContaining({
      effective_on: '2026-08-14',
      row_version: 0,
    }))
    expect(wrapper.get('[data-test="registration-a1-saved"]').text()).toContain('version')

    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    expect(m.preview).toHaveBeenCalledWith(5, 'test')
    expect(m.prepare).toHaveBeenCalledWith(5, 'test')
    expect(m.saveA1Profile.mock.invocationCallOrder[0])
      .toBeLessThan(m.preview.mock.invocationCallOrder[0])
    expect(m.preview.mock.invocationCallOrder[0])
      .toBeLessThan(m.prepare.mock.invocationCallOrder[0])
    expect(wrapper.find('[data-test="registration-prepared"]').exists()).toBe(true)
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

  it('loads an immutable REGZEC event and uses it for preview and prepare', async () => {
    m.events.mockResolvedValue([{
      id: 91,
      employment_id: 5,
      environment: 'test',
      interaction: 'change',
      action_code: 3,
      effective_on: '2026-08-26',
      source_kind: 'verified_change',
      source_reference: 'personnel-change-18',
      snapshot_fingerprint: 'c'.repeat(64),
      approved_at: '2026-08-26 09:00:00',
      consumed: false,
      created: true,
    }])
    m.preview.mockResolvedValue({
      ...preview,
      agenda_code: 'REGZEC25',
      interaction: 'change',
      action_code: 3,
    })
    m.prepare.mockResolvedValue({
      submission_id: 21,
      obligation_id: 22,
      part_id: 23,
      artifact_id: 24,
      status: 'ready',
      row_version: 1,
      environment: 'test',
      agenda_code: 'REGZEC25',
      interaction: 'change',
      artifact_sha256: 'd'.repeat(64),
      created: true,
      deadline,
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-select"]').setValue('91')
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    expect(m.preview).toHaveBeenCalledWith(5, 'test', 91)

    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()
    expect(m.prepare).toHaveBeenCalledWith(5, 'test', 91)
  })

  it('creates an A5 source, selects it and previews the exact event', async () => {
    const event = {
      id: 92,
      employment_id: 5,
      environment: 'test',
      interaction: 'variable_symbol_transfer',
      action_code: 5,
      effective_on: '2026-08-26',
      source_kind: 'employer_transfer',
      source_reference: 'transfer-decision-4',
      snapshot_fingerprint: 'e'.repeat(64),
      approved_at: '2026-08-26 10:00:00',
      consumed: false,
      created: true,
    }
    m.approveEvent.mockResolvedValue(event)
    m.preview.mockResolvedValue({
      ...preview,
      agenda_code: 'REGZEC25',
      interaction: 'variable_symbol_transfer',
      action_code: 5,
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    await wrapper.get('[data-test="registration-event-interaction"]').setValue('variable_symbol_transfer')
    await wrapper.get('[data-test="registration-event-effective-on"]').setValue('2026-08-26')
    await wrapper.get('[data-test="registration-event-source-reference"]').setValue('transfer-decision-4')
    await wrapper.get('[data-test="registration-event-new-variable-symbol"]').setValue('9990005678')
    await wrapper.get('[data-test="registration-event-save"]').trigger('click')
    await flushPromises()

    expect(m.approveEvent).toHaveBeenCalledWith(5, expect.objectContaining({
      environment: 'test',
      interaction: 'variable_symbol_transfer',
      effective_on: '2026-08-26',
      source_reference: 'transfer-decision-4',
      new_variable_symbol: '9990005678',
    }))
    expect(m.preview).toHaveBeenCalledWith(5, 'test', 92)
  })

  it('requires an explicit no-show confirmation for A8 and binds the source submission', async () => {
    m.approveEvent.mockResolvedValue({
      id: 93,
      employment_id: 5,
      environment: 'test',
      interaction: 'cancellation',
      action_code: 8,
      effective_on: '2026-08-20',
      source_kind: 'verified_cancellation',
      source_reference: 'no-show-record-1',
      snapshot_fingerprint: 'f'.repeat(64),
      approved_at: '2026-08-26 11:00:00',
      consumed: false,
      created: true,
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    await wrapper.get('[data-test="registration-event-interaction"]').setValue('cancellation')
    await wrapper.get('[data-test="registration-event-effective-on"]').setValue('2026-08-20')
    await wrapper.get('[data-test="registration-event-source-reference"]').setValue('no-show-record-1')
    await wrapper.get('[data-test="registration-event-source-submission-id"]').setValue('44')

    expect(wrapper.get('[data-test="registration-event-save"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="registration-event-not-started"]').setValue(true)
    await wrapper.get('[data-test="registration-event-save"]').trigger('click')
    await flushPromises()

    expect(m.approveEvent).toHaveBeenCalledWith(5, expect.objectContaining({
      interaction: 'cancellation',
      source_submission_id: 44,
      not_started: true,
    }))
  })

  it('exposes guided fields for every REGZEC interaction A2 through A8', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    const interaction = wrapper.get('[data-test="registration-event-interaction"]')

    expect(wrapper.find('[data-test="registration-event-a2"]').exists()).toBe(true)

    await interaction.setValue('change')
    expect(wrapper.find('[data-test="registration-event-delta"]').exists()).toBe(true)

    await interaction.setValue('correction')
    expect(wrapper.find('[data-test="registration-event-source-submission-id"]').exists()).toBe(true)

    await interaction.setValue('variable_symbol_transfer')
    expect(wrapper.find('[data-test="registration-event-new-variable-symbol"]').exists()).toBe(true)

    await interaction.setValue('czech_legislation_start')
    expect(wrapper.find('[data-test="registration-event-foreign-insurance"]').exists()).toBe(true)

    await interaction.setValue('czech_legislation_end')
    expect(wrapper.find('[data-test="registration-event-foreign-insurance"]').exists()).toBe(true)

    await interaction.setValue('cancellation')
    expect(wrapper.find('[data-test="registration-event-a8"]').exists()).toBe(true)
  })
})
