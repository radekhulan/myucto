import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  jmhzTransportHistory: vi.fn(),
  pollJmhzTransportAttempt: vi.fn(),
  closeJmhzTransportAttempt: vi.fn(),
  cancelJmhzSubmission: vi.fn(),
  jmhzCorrectableComponents: vi.fn(),
  cancelJmhzSubmissionComponents: vi.fn(),
  jmhzContentCorrectionCandidates: vi.fn(),
  jmhzContentCorrectionPreparations: vi.fn(),
  freezeJmhzContentCorrection: vi.fn(),
  jmhzImportedProtocols: vi.fn(),
  jmhzImportedProtocolErrors: vi.fn(),
  importJmhzProtocol: vi.fn(),
  employerSettings: vi.fn(),
  submissionDetail: vi.fn(),
  sendJmhzTransport: vi.fn(),
  enqueueJmhzIsds: vi.fn(),
  gatewayStartPayroll: vi.fn(),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzTransportHistory: m.jmhzTransportHistory,
    pollJmhzTransportAttempt: m.pollJmhzTransportAttempt,
    closeJmhzTransportAttempt: m.closeJmhzTransportAttempt,
    cancelJmhzSubmission: m.cancelJmhzSubmission,
    jmhzCorrectableComponents: m.jmhzCorrectableComponents,
    cancelJmhzSubmissionComponents: m.cancelJmhzSubmissionComponents,
    jmhzContentCorrectionCandidates: m.jmhzContentCorrectionCandidates,
    jmhzContentCorrectionPreparations: m.jmhzContentCorrectionPreparations,
    freezeJmhzContentCorrection: m.freezeJmhzContentCorrection,
    jmhzImportedProtocols: m.jmhzImportedProtocols,
    jmhzImportedProtocolErrors: m.jmhzImportedProtocolErrors,
    importJmhzProtocol: m.importJmhzProtocol,
    employerSettings: m.employerSettings,
    submissionDetail: m.submissionDetail,
    sendJmhzTransport: m.sendJmhzTransport,
    enqueueJmhzIsds: m.enqueueJmhzIsds,
  },
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: { gatewayStartPayroll: m.gatewayStartPayroll },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    locale: { value: 'cs' },
  }),
}))

import PayrollTransportHistoryPanel from '@/pages/payroll/PayrollTransportHistoryPanel.vue'

function attempt(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    supplier_id: 1,
    environment: 'production',
    submission_id: 70,
    channel: 'vrep_apep',
    attempt_no: 1,
    status: 'awaiting_protocol',
    period_start: '2026-07-01',
    period_end: '2026-07-31',
    submission_kind: 'regular',
    submission_status: 'accepted',
    corrects_submission_id: null,
    correlation_reference: 'ABC-123-XYZ',
    request_sha256: 'a'.repeat(64),
    response_http_status: 200,
    error_code: null,
    error_message: null,
    next_retry_at: null,
    poll_count: 0,
    last_polled_at: null,
    last_poll_error: null,
    sent_at: '2026-08-10 09:00:00',
    completed_at: null,
    closed_at: null,
    close_attempts: 0,
    close_error: null,
    row_version: 2,
    created_by: 3,
    created_at: '2026-08-10 08:59:00',
    updated_at: '2026-08-10 09:00:00',
    ...overrides,
  }
}

function protocol(overrides: Record<string, unknown> = {}) {
  return {
    id: 11,
    supplier_id: 1,
    environment: 'production',
    protocol_kind: 'processing',
    variable_symbol: '1234567890',
    period_month: 6,
    period_year: 2026,
    submission_guid: '0195AAAA-1111-7222-8333-BBBBCCCCDDDD',
    correlation_reference: 'AAAA1111BBBB2222CCCC3333DDDD4444',
    status_code: 1,
    status_name: 'ProcessedAndComplete',
    error_count: 0,
    protocol_dated_at: '2026-07-02T16:20:20.382+02:00',
    submitted_at: '2026-07-02T16:15:36+02:00',
    source_filename: 'PROTOKOL062026.xml',
    payload_sha256: 'c'.repeat(64),
    row_version: 1,
    imported_by: 3,
    created_at: '2026-08-12 09:00:00',
    updated_at: '2026-08-12 09:00:00',
    errors: [],
    detail_available: true,
    ...overrides,
  }
}

describe('PayrollTransportHistoryPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.jmhzImportedProtocols.mockResolvedValue({
      environment: 'production',
      protocols: [],
    })
    m.employerSettings.mockResolvedValue({
      offices: [{
        id: 42,
        code: 'MAIN',
        name: 'Hlavní účtárna',
        social_security_variable_symbol: '1234567890',
        is_active: true,
      }],
    })
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt()],
    })
    m.jmhzContentCorrectionPreparations.mockResolvedValue({
      environment: 'production',
      submission_id: 70,
      preparations: [{
        id: 125,
        source_revision_id: 301,
        revision_no: 2,
        period_start: '2026-07-01',
        created_at: '2026-08-20 09:00:00',
        document_sha256: 'f'.repeat(64),
      }],
      auto_selected_preparation_id: 125,
    })
    m.sendJmhzTransport.mockResolvedValue({
      attempt: attempt({ id: 81, submission_id: 91 }),
      acknowledgement: null,
      settled: false,
      report: null,
    })
    m.enqueueJmhzIsds.mockResolvedValue({
      outbox_id: 77,
      created: true,
      environment: 'production',
      recipient: { environment: 'production', box_id: '5ffu6xk', name: 'ČSSZ', note: '' },
      subject: 'JMHZ 2026-07',
      sender_ident: 'MU-JMHZ',
      attachment: { filename: 'jmhz.xml', mime: 'application/xml', sha256: 'a', bytes: 10 },
      transport: { automatic: false, channel: 'manual_upload', reason: 'gateway_unavailable' },
      response_hint: { subject_prefix: 'JMHZ', attachment_prefix: 'JMHZ', note: '' },
    })
    m.gatewayStartPayroll.mockResolvedValue({
      session_id: 1,
      app_token: 'token',
      redirect_url: 'https://www.datovka.gov.cz/as/login',
      login_guidance: 'Přihlaste se metodou, kterou nabízí ISDS.',
      login_policy_documented: false,
      expires_at: '2026-08-26 08:00:00',
      resumed: false,
    })
  })

  it('seskupí pokusy jednoho podání a zachová pořadí z ledgeru', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [
        attempt({ id: 3, attempt_no: 2, status: 'completed', completed_at: '2026-08-11 10:00:00' }),
        attempt({ id: 2, attempt_no: 1, status: 'failed', error_code: 'jmhz_vrep_http_error' }),
        attempt({ id: 9, submission_id: 71, attempt_no: 1, status: 'sent' }),
      ],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(m.jmhzTransportHistory).toHaveBeenCalledWith('production', { limit: 25, offset: 0 })
    const group = wrapper.get('[data-test="transport-group-70"]')
    expect(group.text()).toContain('payroll.submissions.transport.group.attempts 2')
    expect(group.text()).toContain('2026-07-01')
    const numbers = group.findAll('[data-test^="transport-attempt-"]')
      .map(node => node.attributes('data-test'))
    expect(numbers).toEqual(['transport-attempt-3', 'transport-attempt-2'])
    expect(wrapper.find('[data-test="transport-group-71"]').exists()).toBe(true)
  })

  it('připravené storno odešle přes VREP podle jeho vlastního ID', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt()],
      ready_submissions: [{
        submission_id: 91,
        submission_kind: 'cancellation',
        submission_status: 'ready',
        corrects_submission_id: 70,
        period_start: '2026-07-01',
        period_end: '2026-07-31',
        created_at: '2026-08-26 07:00:00',
        outbox_id: null,
        outbox_dispatch_state: null,
        outbox_acceptance_state: null,
        outbox_external_message_id: null,
      }],
    })
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    await wrapper.get('[data-test="transport-ready-vrep-91"]').trigger('click')
    await flushPromises()

    expect(m.sendJmhzTransport).toHaveBeenCalledWith(
      91,
      '1234567890',
      'production',
      expect.any(String),
    )
  })

  it('připravené storno vloží do ISDS fronty pouze po kliknutí uživatele', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt()],
      ready_submissions: [{
        submission_id: 91,
        submission_kind: 'cancellation',
        submission_status: 'ready',
        corrects_submission_id: 70,
        period_start: '2026-07-01',
        period_end: '2026-07-31',
        created_at: '2026-08-26 07:00:00',
        outbox_id: null,
        outbox_dispatch_state: null,
        outbox_acceptance_state: null,
        outbox_external_message_id: null,
      }],
    })
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(m.enqueueJmhzIsds).not.toHaveBeenCalled()
    await wrapper.get('[data-test="transport-ready-isds-91"]').trigger('click')
    await flushPromises()

    expect(m.enqueueJmhzIsds).toHaveBeenCalledWith(91, 'production')
    expect(m.gatewayStartPayroll).not.toHaveBeenCalled()
  })

  it('u aktivní ISDS brány čeká s přesměrováním na další potvrzení uživatele', async () => {
    m.enqueueJmhzIsds.mockResolvedValue({
      ...(await m.enqueueJmhzIsds()),
      transport: { automatic: true, channel: 'gateway', reason: null },
    })
    m.enqueueJmhzIsds.mockClear()
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [],
      ready_submissions: [{
        submission_id: 91,
        submission_kind: 'cancellation',
        submission_status: 'ready',
        corrects_submission_id: 70,
        period_start: '2026-07-01',
        period_end: '2026-07-31',
        created_at: '2026-08-26 07:00:00',
        outbox_id: null,
        outbox_dispatch_state: null,
        outbox_acceptance_state: null,
        outbox_external_message_id: null,
      }],
    })
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    await wrapper.get('[data-test="transport-ready-isds-91"]').trigger('click')
    await flushPromises()

    expect(m.gatewayStartPayroll).toHaveBeenCalledWith(77)
    expect(wrapper.find('[data-test="transport-ready-gateway-91"]').exists()).toBe(true)
  })

  it('podání už vložené do ISDS fronty nenabídne k duplicitnímu odeslání', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [],
      ready_submissions: [{
        submission_id: 91,
        submission_kind: 'cancellation',
        submission_status: 'ready',
        corrects_submission_id: 70,
        period_start: '2026-07-01',
        period_end: '2026-07-31',
        created_at: '2026-08-26 07:00:00',
        outbox_id: 77,
        outbox_dispatch_state: 'ready',
        outbox_acceptance_state: 'unknown',
        outbox_external_message_id: null,
      }],
    })
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect((wrapper.get('[data-test="transport-ready-isds-91"]').element as HTMLButtonElement).disabled)
      .toBe(true)
    expect((wrapper.get('[data-test="transport-ready-vrep-91"]').element as HTMLButtonElement).disabled)
      .toBe(true)
    expect(wrapper.get('[data-test="transport-ready-existing-outbox-91"]').text()).toContain('77')
    expect(m.enqueueJmhzIsds).not.toHaveBeenCalled()
    expect(m.sendJmhzTransport).not.toHaveBeenCalled()
  })

  it('převzetí neoznačí jako přijaté a uzavření u něj vůbec nenabídne', async () => {
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-status-1"]').text())
      .toBe('payroll.submissions.transport.status.awaiting_protocol')
    expect(wrapper.get('[data-test="transport-awaiting-note-1"]').text())
      .toContain('payroll.submissions.transport.awaiting_note')
    expect(wrapper.find('[data-test="transport-close-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-poll-1"]').exists()).toBe(true)
  })

  it('selhaný pokus ukáže kód i hlášku rovnou, bez rozklikávání', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        id: 5,
        status: 'failed',
        error_code: 'jmhz_vrep_unavailable',
        error_message: 'Brána VREP odpověděla chybou 503.',
        response_http_status: 503,
      })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    const failure = wrapper.get('[data-test="transport-failure-5"]')
    expect(failure.text()).toContain('jmhz_vrep_unavailable')
    expect(failure.text()).toContain('Brána VREP odpověděla chybou 503.')
    expect(wrapper.get('[data-test="transport-attempt-5"]').text()).toContain('503')
  })

  it('selhané načtení nikdy nevykreslí jako „nic neodesláno"', async () => {
    m.jmhzTransportHistory.mockRejectedValue({
      response: { data: { error: { message: 'Databáze je nedostupná.' } } },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-load-error"]').text())
      .toContain('Databáze je nedostupná.')
    expect(wrapper.get('[data-test="transport-load-error"]').text())
      .toContain('payroll.submissions.transport.state_unknown')
    expect(wrapper.find('[data-test="transport-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-loading"]').exists()).toBe(false)
  })

  it('rozliší načítání, prázdný ledger a chybu', async () => {
    m.jmhzTransportHistory.mockReturnValue(new Promise(() => {}))
    const pending = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    expect(pending.find('[data-test="transport-loading"]').exists()).toBe(true)
    expect(pending.find('[data-test="transport-empty"]').exists()).toBe(false)

    m.jmhzTransportHistory.mockResolvedValue({ environment: 'production', attempts: [] })
    const empty = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    expect(empty.get('[data-test="transport-empty"]').text())
      .toContain('payroll.submissions.transport.empty.title')
    expect(empty.find('[data-test="transport-load-error"]').exists()).toBe(false)
  })

  it('bez variabilního symbolu se nedoptá a řekne proč', async () => {
    m.employerSettings.mockResolvedValue({ offices: [] })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-vs-missing"]').text())
      .toContain('payroll.submissions.transport.vs.missing')
    expect(wrapper.get('[data-test="transport-vs-required"]').text())
      .toContain('payroll.submissions.transport.vs.required')
    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()
    expect(m.pollJmhzTransportAttempt).not.toHaveBeenCalled()
  })

  it('jednoznačný variabilní symbol převezme z nastavení a pošle ho při doptání', async () => {
    m.pollJmhzTransportAttempt.mockResolvedValue({
      attempt: attempt(),
      acknowledgement: {
        correlation_id: 'ABC-123-XYZ',
        poll_interval_seconds: 60,
        gateway_timestamp: '2026-08-10 09:05:00',
      },
      settled: false,
      report: null,
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(
      (wrapper.get('[data-test="transport-variable-symbol"]').element as HTMLInputElement).value,
    ).toBe('1234567890')

    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()

    expect(m.pollJmhzTransportAttempt).toHaveBeenCalledWith(1, '1234567890', 'production')
    // Potvrzení o převzetí není výsledek — text o běžícím zpracování, ne o přijetí.
    expect(wrapper.get('[data-test="transport-acknowledgement-1"]').text())
      .toContain('payroll.submissions.transport.acknowledged 60')
    expect(wrapper.find('[data-test="transport-report-1"]').exists()).toBe(false)
  })

  it('protokol vypíše chyby včetně názvu kontroly a dotčených atributů', async () => {
    m.pollJmhzTransportAttempt.mockResolvedValue({
      attempt: attempt({ status: 'completed', completed_at: '2026-08-11 10:00:00' }),
      acknowledgement: null,
      settled: true,
      report: {
        status: 'PartiallyAccepted',
        errors: [
          {
            code: 20370,
            message: 'Pojistné neodpovídá vyměřovacímu základu.',
            origin: 'dis',
            control_id: 370,
            form_guid: 'form-1',
            ik_mpsv: '123456789',
            id_ppv: 'PPV-1',
            control: {
              name: 'Kontrola pojistného',
              detail: 'Pojistné musí odpovídat vyměřovacímu základu.',
              area: 'Pojistné',
              category: 'F1',
              attribute_ids: ['10370', '10477'],
            },
          },
          {
            code: 20022,
            message: 'Neznámá vada podání.',
            origin: 'dis',
            control_id: 22,
            form_guid: null,
            ik_mpsv: null,
            id_ppv: null,
            control: null,
          },
        ],
      },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()

    const report = wrapper.get('[data-test="transport-report-1"]')
    expect(report.text())
      .toContain('payroll.submissions.transport.protocol_status.PartiallyAccepted')

    const first = wrapper.get('[data-test="transport-report-error-1-0"]')
    expect(first.text()).toContain('Pojistné neodpovídá vyměřovacímu základu.')
    expect(first.text()).toContain('Kontrola pojistného')
    const attributes = wrapper.get('[data-test="transport-report-attributes-1-0"]')
    expect(attributes.text()).toContain('10370')
    expect(attributes.text()).toContain('10477')

    // Kontrola mimo náš katalog se nesmí zamlčet — hláška zůstává vidět.
    const second = wrapper.get('[data-test="transport-report-error-1-1"]')
    expect(second.text()).toContain('Neznámá vada podání.')
    expect(wrapper.get('[data-test="transport-report-uncatalogued-1-1"]').text())
      .toContain('payroll.submissions.transport.report.control_unknown')
  })

  it('uzavřít nabídne jen u dotaženého protokolu a pošle variabilní symbol', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ id: 8, status: 'completed', completed_at: '2026-08-11 10:00:00' })],
    })
    m.closeJmhzTransportAttempt.mockResolvedValue({
      closed: true,
      already_closed: false,
      attempt: attempt({ id: 8, status: 'completed', closed_at: '2026-08-11 10:05:00' }),
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-close-note-8"]').text())
      .toContain('payroll.submissions.transport.close_note')
    await wrapper.get('[data-test="transport-close-8"]').trigger('click')
    await flushPromises()

    expect(m.closeJmhzTransportAttempt).toHaveBeenCalledWith(8, '1234567890', 'production')
    expect(wrapper.get('[data-test="transport-success"]').text())
      .toContain('payroll.submissions.transport.closed 8')
  })

  it('v režimu jen pro čtení uzavření nenabídne, doptat se ale nechá', async () => {
    m.canWrite.mockReturnValue(false)
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ id: 8, status: 'completed' })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-close-8"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-poll-8"]').exists()).toBe(true)
  })

  it('bez přiděleného CorrelationID doptání nenabízí', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ id: 4, status: 'prepared', correlation_reference: null })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-poll-4"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="transport-correlation-4"]').text())
      .toContain('payroll.submissions.transport.correlation_missing')
  })

  it('přepnutí prostředí načte ledger znovu — testovací podání je jiné', async () => {
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    await wrapper.get('[data-test="transport-environment-test"]').trigger('click')
    await flushPromises()

    expect(m.jmhzTransportHistory).toHaveBeenLastCalledWith('test', { limit: 25, offset: 0 })
    expect(wrapper.get('[data-test="transport-environment-note"]').text())
      .toContain('payroll.submissions.transport.environment.test_note')
  })

  it('chybu doptání vypíše a seznam pokusů nevyprázdní', async () => {
    m.pollJmhzTransportAttempt.mockRejectedValue({
      response: { data: { error: { message: 'Brána VREP neodpovídá.' } } },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="transport-error"]').text())
      .toContain('Brána VREP neodpovídá.')
    expect(wrapper.find('[data-test="transport-attempt-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="transport-empty"]').exists()).toBe(false)
  })

  it('nedohledané období nezabrání zobrazení stavů', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ period_start: null, period_end: null })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-group-70"]').text())
      .toContain('payroll.submissions.transport.group.period_unknown')
    expect(wrapper.find('[data-test="transport-status-1"]').exists()).toBe(true)
  })

  /**
   * Období nese ledger, takže se na ně přehled nesmí doptávat po jednom podání.
   * Kdyby ano, každý řádek by stál jeden HTTP požadavek navíc — tenhle test je
   * pojistka proti návratu toho rozstřelu.
   */
  it('období vezme z ledgeru a na detail podání se vůbec neptá', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [
        attempt({ id: 3, submission_id: 70 }),
        attempt({ id: 2, submission_id: 70, attempt_no: 2 }),
        attempt({
          id: 9,
          submission_id: 71,
          period_start: '2026-06-01',
          period_end: '2026-06-30',
        }),
      ],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(m.submissionDetail).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="transport-group-70"]').text())
      .toContain('payroll.submissions.transport.group.period 2026-07-01 2026-07-31')
    expect(wrapper.get('[data-test="transport-group-71"]').text())
      .toContain('payroll.submissions.transport.group.period 2026-06-01 2026-06-30')
    expect(m.jmhzTransportHistory).toHaveBeenCalledTimes(1)
  })

  /** Odpověď na doptání je holý řádek ledgeru — období se z hlavičky ztratit nesmí. */
  it('po doptání zůstane období vidět, i když ho odpověď nenese', async () => {
    const polled = attempt({ status: 'completed', completed_at: '2026-08-11 10:00:00' })
    delete (polled as Record<string, unknown>).period_start
    delete (polled as Record<string, unknown>).period_end
    m.pollJmhzTransportAttempt.mockResolvedValue({
      attempt: polled,
      acknowledgement: null,
      settled: true,
      report: { status: 'ProcessedAndComplete', errors: [] },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="transport-group-70"]').text())
      .toContain('payroll.submissions.transport.group.period 2026-07-01 2026-07-31')
    expect(m.submissionDetail).not.toHaveBeenCalled()
  })

  /**
   * Firma, která podala cizím softwarem, má ledger prázdný. Bez načteného
   * protokolu by se obrazovka tvářila, že nepodala — a to je přesně ta lež,
   * kvůli které tahle funkce vznikla.
   */
  it('načtený protokol se ukáže i tam, kde ledger nemá nic', async () => {
    m.jmhzTransportHistory.mockResolvedValue({ environment: 'production', attempts: [] })
    m.jmhzImportedProtocols.mockResolvedValue({
      environment: 'production',
      protocols: [protocol()],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-empty"]').exists()).toBe(false)
    const card = wrapper.get('[data-test="transport-imported-11"]')
    expect(card.text()).toContain('payroll.submissions.transport.imported.period 6 2026')
    expect(card.text()).toContain('0195AAAA-1111-7222-8333-BBBBCCCCDDDD')
    expect(wrapper.get('[data-test="transport-imported-status-11"]').text())
      .toContain('payroll.submissions.transport.protocol_status.ProcessedAndComplete')
  })

  /** Zdroj musí být vidět: u načteného protokolu se nedá doptat ani uzavřít. */
  it('rozliší, co odeslala aplikace a co je jen načtený doklad', async () => {
    m.jmhzImportedProtocols.mockResolvedValue({
      environment: 'production',
      protocols: [protocol()],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-source-app-70"]').text())
      .toContain('payroll.submissions.transport.source.app')
    expect(wrapper.get('[data-test="transport-source-imported-11"]').text())
      .toContain('payroll.submissions.transport.source.imported')
    expect(wrapper.get('[data-test="transport-imported-note-11"]').text())
      .toContain('payroll.submissions.transport.imported.note')
    // Načtený protokol nesmí nabízet akce, které bez datové věty nejdou.
    expect(wrapper.find('[data-test="transport-poll-11"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-close-11"]').exists()).toBe(false)
  })

  it('řadí naše pokusy a načtené protokoly v jednom pořadí podle období', async () => {
    m.jmhzImportedProtocols.mockResolvedValue({
      environment: 'production',
      protocols: [protocol({ id: 11, period_month: 6, period_year: 2026 })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    const order = wrapper.findAll('[data-test^="transport-group-"], [data-test^="transport-imported-1"]')
      .map(node => node.attributes('data-test'))
      .filter(value => value === 'transport-group-70' || value === 'transport-imported-11')
    // Podání za 07/2026 je novější než protokol za 06/2026.
    expect(order).toEqual(['transport-group-70', 'transport-imported-11'])
  })

  it('protokol s chybou dotáhne rozpad až po rozbalení a zapamatuje si ho', async () => {
    m.jmhzTransportHistory.mockResolvedValue({ environment: 'production', attempts: [] })
    m.jmhzImportedProtocols.mockResolvedValue({
      environment: 'production',
      protocols: [protocol({
        status_code: 3,
        status_name: 'Rejected',
        error_count: 1,
      })],
    })
    m.jmhzImportedProtocolErrors.mockResolvedValue({
      environment: 'production',
      protocol_id: 11,
      detail_available: true,
      errors: [{
        code: 20301,
        message: 'Pojistné neodpovídá vyměřovacímu základu.',
        origin: 'dis',
        control_id: 301,
        form_guid: 'AAAABBBB-1111-7222-8333-CCCCDDDDEEEE',
        ik_mpsv: null,
        id_ppv: null,
        control: {
          name: 'Kontrola pojistného',
          detail: null,
          area: 'Pojistné',
          category: 'F1',
          attribute_ids: ['10477'],
        },
      }],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    // Seznam nese jen počet; detail se do prohlížeče netahá, dokud si ho
    // uživatel nevyžádá.
    expect(m.jmhzImportedProtocolErrors).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="transport-imported-error-count-11"]').text()).toBe('1')
    expect(wrapper.find('[data-test="transport-imported-error-11-0"]').exists()).toBe(false)

    await wrapper.get('[data-test="transport-imported-errors-toggle-11"]').trigger('click')
    await flushPromises()

    expect(m.jmhzImportedProtocolErrors).toHaveBeenCalledWith(11, 'production')
    const error = wrapper.get('[data-test="transport-imported-error-11-0"]')
    expect(error.text()).toContain('20301')
    expect(error.text()).toContain('Pojistné neodpovídá vyměřovacímu základu.')
    expect(error.text()).toContain('Kontrola pojistného')
    expect(error.text()).toContain('10477')

    // Zabalit a znovu rozbalit už na server nechodí — výsledek si držíme.
    await wrapper.get('[data-test="transport-imported-errors-toggle-11"]').trigger('click')
    await wrapper.get('[data-test="transport-imported-errors-toggle-11"]').trigger('click')
    await flushPromises()
    expect(m.jmhzImportedProtocolErrors).toHaveBeenCalledTimes(1)
  })

  it('načte protokol ze souboru a potvrdí, co ČSSZ hlásí', async () => {
    m.importJmhzProtocol.mockResolvedValue({
      environment: 'production',
      protocol: protocol(),
      created: true,
      errors: [],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    const file = new File(['<ProtokolOZpracovani/>'], 'protokol.xml', { type: 'text/xml' })
    const input = wrapper.get('[data-test="transport-import-input"]')
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await flushPromises()

    expect(m.importJmhzProtocol).toHaveBeenCalledWith(file, 'production')
    // Po načtení se přehled dotáhne znovu, aby doklad rovnou stál v seznamu.
    expect(m.jmhzImportedProtocols).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-test="transport-success"]').text())
      .toContain('payroll.submissions.transport.imported.added')
  })

  it('opakované načtení téhož protokolu se nehlásí jako nové podání', async () => {
    m.importJmhzProtocol.mockResolvedValue({
      environment: 'production',
      protocol: protocol(),
      created: false,
      errors: [],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    const input = wrapper.get('[data-test="transport-import-input"]')
    Object.defineProperty(input.element, 'files', {
      value: [new File(['<x/>'], 'protokol.xml')],
      configurable: true,
    })
    await input.trigger('change')
    await flushPromises()

    expect(wrapper.get('[data-test="transport-success"]').text())
      .toContain('payroll.submissions.transport.imported.replaced')
  })

  it('odmítnutý cizí protokol se vypíše jako chyba a nic nepřidá', async () => {
    m.importJmhzProtocol.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Protokol je vystavený na variabilní symbol 9999999999,'
              + ' který této firmě nepatří.',
          },
        },
      },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    const input = wrapper.get('[data-test="transport-import-input"]')
    Object.defineProperty(input.element, 'files', {
      value: [new File(['<x/>'], 'cizi.xml')],
      configurable: true,
    })
    await input.trigger('change')
    await flushPromises()

    expect(wrapper.get('[data-test="transport-error"]').text()).toContain('nepatří')
    expect(wrapper.findAll('[data-test^="transport-imported-"]')).toHaveLength(0)
  })

  /**
   * Automatika musí být VIDĚT. Bez toho uživatel neví, jestli se aplikace ptá
   * sama, nebo jestli podání čeká na něj — a to je přesně ten rozdíl, kvůli
   * kterému se přestane sledovat výsledek.
   */
  it('u čekajícího podání ukáže, kdy se aplikace zeptá znovu', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        id: 12,
        poll_count: 3,
        last_polled_at: '2026-08-11 09:00:00',
        next_retry_at: '2026-08-11 10:00:00',
        last_poll_error: 'Brána VREP neodpověděla.',
      })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    const automation = wrapper.get('[data-test="transport-automation-12"]')
    expect(automation.text()).toContain('payroll.submissions.transport.automation.next_poll 2026-08-11 10:00:00')
    expect(automation.text()).toContain('payroll.submissions.transport.automation.polls 3')
    expect(wrapper.get('[data-test="transport-poll-error-12"]').text())
      .toContain('Brána VREP neodpověděla.')
  })

  /**
   * Uzavřenou transakci nemá smysl nabízet znovu — druhé uzavření by u ČSSZ
   * byl dotaz na transakci, která už neexistuje.
   */
  it('u uzavřené transakce tlačítko nenabídne a řekne, že je uzavřená', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        id: 13,
        status: 'completed',
        completed_at: '2026-08-11 10:00:00',
        closed_at: '2026-08-11 10:05:00',
        close_attempts: 1,
        poll_count: 2,
      })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-close-13"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="transport-closed-13"]').text())
      .toContain('payroll.submissions.transport.automation.closed 2026-08-11 10:05:00')
  })

  /**
   * Pokus, který automatika vzdala, musí nést větu, podle které se dá jednat —
   * ne kód a ne mlčení.
   */
  it('vzdaný pokus řekne, co má uživatel udělat', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        id: 14,
        status: 'expired',
        error_code: 'jmhz_protocol_not_delivered',
        error_message: 'ČSSZ protokol nevydala.',
        poll_count: 30,
      })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-expired-note-14"]').text())
      .toContain('payroll.submissions.transport.automation.expired_note')
    expect(wrapper.get('[data-test="transport-failure-14"]').text())
      .toContain('ČSSZ protokol nevydala.')
  })

  /**
   * Storno ruší u ČSSZ všechna hlášení za období a vzít zpět se nedá, takže se
   * nesmí spustit jedním kliknutím.
   */
  it('storno se nejdřív potvrzuje a teprve pak připraví podání', async () => {
    m.cancelJmhzSubmission.mockResolvedValue({
      submission_id: 91,
      part_id: 1,
      artifact_id: 2,
      status: 'ready',
      row_version: 3,
      environment: 'production',
      artifact_sha256: 'd'.repeat(64),
      created: true,
      submission_kind: 'cancellation',
      corrects_submission_id: 70,
      submission_guid: '0195AAAA-1111-7222-8333-BBBBCCCCDDDD',
      variable_symbol: '1234567890',
      month: 7,
      year: 2026,
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    await wrapper.get('[data-test="transport-cancel-70"]').trigger('click')
    expect(m.cancelJmhzSubmission).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="transport-cancel-confirm-70"]').text())
      .toContain('payroll.submissions.transport.storno.confirm_text')

    await wrapper.get('[data-test="transport-cancel-submit-70"]').trigger('click')
    await flushPromises()

    expect(m.cancelJmhzSubmission).toHaveBeenCalledWith(70, 'production')
    expect(wrapper.get('[data-test="transport-success"]').text())
      .toContain('payroll.submissions.transport.storno.frozen 91')
  })

  it('obsahová oprava rozliší opravu přijatého a doplnění odmítnutého formuláře', async () => {
    const components = [{
      employee_name: 'Jana Syntetická',
      person_external_identifier: '1234567890',
      employment_external_identifier: '987654321',
      effective_state: 'accepted',
      action: 'correct_values',
    }, {
      employee_name: 'Petr Syntetický',
      person_external_identifier: '1234567891',
      employment_external_identifier: '987654322',
      effective_state: 'rejected',
      action: 'complete_form',
    }]
    m.jmhzContentCorrectionCandidates.mockResolvedValue({
      environment: 'production',
      submission_id: 70,
      preparation_id: 125,
      document_sha256: 'f'.repeat(64),
      forms: components,
    })
    m.freezeJmhzContentCorrection.mockResolvedValue({
      submission_id: 92,
      part_id: 1,
      artifact_id: 2,
      status: 'ready',
      row_version: 3,
      environment: 'production',
      artifact_sha256: 'e'.repeat(64),
      created: true,
      submission_kind: 'correction',
      corrects_submission_id: 70,
      submission_guid: '0195AAAA-1111-7222-8333-BBBBCCCCDDDD',
      variable_symbol: '1234567890',
      month: 7,
      year: 2026,
    })
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        status: 'completed',
        completed_at: '2026-08-11 10:00:00',
      })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    await wrapper.get('[data-test="transport-correct-70"]').trigger('click')
    await flushPromises()
    expect(m.jmhzContentCorrectionCandidates).toHaveBeenCalledWith(70, 125, 'production')
    expect(wrapper.get('[data-test="transport-correct-form-70"]').text())
      .toContain('Petr Syntetický')
    expect(wrapper.get('[data-test="transport-correct-form-70"]').text())
      .toContain('payroll.submissions.transport.correction.action_kind.complete_form')

    const second = wrapper.get(
      '[data-test="transport-correct-component-987654322"] input',
    )
    await second.setValue(true)
    const submit = wrapper.get('[data-test="transport-correct-submit-70"]')
    expect(submit.attributes('disabled')).toBeDefined()
    expect(m.freezeJmhzContentCorrection).not.toHaveBeenCalled()

    await wrapper.get('[data-test="transport-correct-impact"]').setValue(true)
    await wrapper.get('[data-test="transport-correct-submit-70"]').trigger('click')
    await flushPromises()

    expect(m.freezeJmhzContentCorrection).toHaveBeenCalledWith(
      70,
      125,
      'production',
      [components[1]!.employment_external_identifier],
    )
    expect(wrapper.get('[data-test="transport-success"]').text())
      .toContain('payroll.submissions.transport.correction.frozen 92')
  })

  it('jedinou způsobilou přípravu vybere automaticky bez opisování interního ID', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ status: 'completed', completed_at: '2026-08-11 10:00:00' })],
    })
    m.jmhzContentCorrectionCandidates.mockResolvedValue({
      environment: 'production',
      submission_id: 70,
      preparation_id: 125,
      document_sha256: 'f'.repeat(64),
      forms: [{
        employee_name: 'Jana Syntetická',
        person_external_identifier: '1234567890',
        employment_external_identifier: '987654321',
        effective_state: 'accepted',
        action: 'correct_values',
      }],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-correct-70"]').trigger('click')
    await flushPromises()

    expect(m.jmhzContentCorrectionPreparations).toHaveBeenCalledWith(70, 'production')
    expect(m.jmhzContentCorrectionCandidates).toHaveBeenCalledWith(70, 125, 'production')
    expect(wrapper.find('[data-test="transport-correct-preparation-id"]').exists()).toBe(false)
    const form = wrapper.get('[data-test="transport-correct-component-987654321"]')
    expect(form.text()).toContain('Jana Syntetická')
    expect(form.text()).toContain('987654321')
  })

  it('při více způsobilých přípravách čeká na výslovný výběr', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ status: 'completed', completed_at: '2026-08-11 10:00:00' })],
    })
    m.jmhzContentCorrectionPreparations.mockResolvedValue({
      environment: 'production',
      submission_id: 70,
      preparations: [{
        id: 125,
        source_revision_id: 301,
        revision_no: 1,
        period_start: '2026-07-01',
        created_at: '2026-08-19 09:00:00',
        document_sha256: 'e'.repeat(64),
      }, {
        id: 126,
        source_revision_id: 302,
        revision_no: 2,
        period_start: '2026-07-01',
        created_at: '2026-08-20 09:00:00',
        document_sha256: 'f'.repeat(64),
      }],
      auto_selected_preparation_id: null,
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-correct-70"]').trigger('click')
    await flushPromises()

    expect(m.jmhzContentCorrectionCandidates).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="transport-correct-preparation-select"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="transport-correct-preparation-id"]').exists()).toBe(false)
  })

  it('částečnou opravu nabídne až po konečném protokolu', async () => {
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-correct-70"]').exists()).toBe(false)

    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        status: 'completed',
        completed_at: '2026-08-11 10:00:00',
      })],
    })
    await wrapper.get('[data-test="transport-reload"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="transport-correct-70"]').exists()).toBe(true)
  })

  it('nabídne doplnění odmítnutého formuláře u částečně přijatého hlášení', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        submission_status: 'partially_accepted',
        status: 'completed',
        completed_at: '2026-08-11 10:00:00',
      })],
    })
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-correct-70"]').exists()).toBe(true)
  })

  it('nevratné akce nabídne jen nad přijatým řádným hlášením', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ submission_status: 'submitted' })],
    })
    const pending = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(pending.find('[data-test="transport-cancel-70"]').exists()).toBe(false)
    expect(pending.find('[data-test="transport-correct-70"]').exists()).toBe(false)

    pending.unmount()
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        submission_kind: 'cancellation',
        submission_status: 'accepted',
        corrects_submission_id: 60,
        status: 'completed',
        completed_at: '2026-08-11 10:00:00',
      })],
    })
    const cancellation = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(cancellation.find('[data-test="transport-cancel-70"]').exists()).toBe(false)
    expect(cancellation.find('[data-test="transport-correct-70"]').exists()).toBe(false)
  })

  it('během načítání součástí neukáže zavádějící prázdný stav', async () => {
    type EmptyComponents = {
      environment: 'production'
      submission_id: number
      preparation_id: number
      document_sha256: string
      forms: []
    }
    let resolveComponents: ((value: EmptyComponents) => void) | undefined
    m.jmhzContentCorrectionCandidates.mockReturnValue(new Promise<EmptyComponents>(resolve => {
      resolveComponents = resolve
    }))
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        status: 'completed',
        completed_at: '2026-08-11 10:00:00',
      })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-correct-70"]').trigger('click')

    expect(wrapper.find('[data-test="transport-correct-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="transport-correct-empty"]').exists()).toBe(false)

    resolveComponents?.({
      environment: 'production',
      submission_id: 70,
      preparation_id: 125,
      document_sha256: 'f'.repeat(64),
      forms: [],
    })
    await flushPromises()
    expect(wrapper.find('[data-test="transport-correct-loading"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-correct-empty"]').exists()).toBe(true)
  })

  it('zcela odmítnuté podání nenabízí jako základ částečné opravy', async () => {
    m.pollJmhzTransportAttempt.mockResolvedValue({
      attempt: attempt({
        status: 'completed',
        completed_at: '2026-08-11 10:00:00',
      }),
      acknowledgement: null,
      settled: true,
      report: { status: 'Rejected', errors: [] },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="transport-correct-70"]').exists()).toBe(false)
  })

  it('umí vybrat konkrétní chybnou součást z protokolu a hledat ve vztazích', async () => {
    const components = [{
      employee_name: 'Jana Syntetická',
      person_external_identifier: '1234567891',
      employment_external_identifier: '987654321',
      effective_state: 'accepted',
      action: 'correct_values',
    }, {
      employee_name: 'Petr Syntetický',
      person_external_identifier: '1234567891',
      employment_external_identifier: '987654322',
      effective_state: 'rejected',
      action: 'complete_form',
    }]
    m.jmhzContentCorrectionCandidates.mockResolvedValue({
      environment: 'production',
      submission_id: 70,
      preparation_id: 125,
      document_sha256: 'f'.repeat(64),
      forms: components,
    })
    m.pollJmhzTransportAttempt.mockResolvedValue({
      attempt: attempt({
        status: 'completed',
        completed_at: '2026-08-11 10:00:00',
      }),
      acknowledgement: null,
      settled: true,
      report: {
        status: 'PartiallyAccepted',
        errors: [{
          code: 40118,
          message: 'Chybná hodnota.',
          origin: 'cjmhz',
          control_id: 118,
          form_guid: 'AAAABBBB-1111-7222-8333-CCCCDDDDEEF0',
          ik_mpsv: components[1]!.person_external_identifier,
          id_ppv: components[1]!.employment_external_identifier,
          control: null,
        }],
      },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="transport-correct-70"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="transport-correct-protocol-hint"]').text())
      .toContain('payroll.submissions.transport.correction.protocol_errors 1')
    await wrapper.get('[data-test="transport-correct-select-errors"]').trigger('click')
    expect(wrapper.get('[data-test="transport-correct-count"]').text())
      .toContain('payroll.submissions.transport.correction.selection_count 1 2')

    await wrapper.get('[data-test="transport-correct-search"]').setValue('987654322')
    expect(wrapper.find(
      '[data-test="transport-correct-component-987654321"]',
    ).exists()).toBe(false)
    expect(wrapper.find(
      '[data-test="transport-correct-component-987654322"]',
    ).exists()).toBe(true)
  })

  it('v režimu jen pro čtení storno vůbec nenabídne', async () => {
    m.canWrite.mockReturnValue(false)

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-cancel-70"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-correct-70"]').exists()).toBe(false)
  })

  /**
   * Selhání seznamu protokolů je selhání CELÉHO přehledu. Ukázat jen pokusy
   * a protokoly tiše vynechat znamená přehled, který zamlčuje podání.
   */
  it('selhání seznamu protokolů nevykreslí přehled jako úplný', async () => {
    m.jmhzImportedProtocols.mockRejectedValue({
      response: { data: { error: { message: 'Evidence protokolů je nedostupná.' } } },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-load-error"]').text())
      .toContain('Evidence protokolů je nedostupná.')
    expect(wrapper.find('[data-test="transport-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-group-70"]').exists()).toBe(false)
  })

  it('v režimu jen pro čtení načtení protokolu vůbec nenabídne', async () => {
    m.canWrite.mockReturnValue(false)

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-import-protocol"]').exists()).toBe(false)
  })

  it('nečitelný uložený originál nezruší doklad, jen jeho detail', async () => {
    m.jmhzTransportHistory.mockResolvedValue({ environment: 'production', attempts: [] })
    m.jmhzImportedProtocols.mockResolvedValue({
      environment: 'production',
      protocols: [protocol({ error_count: 2, errors: [], detail_available: false })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-imported-11"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="transport-imported-detail-missing-11"]').text())
      .toContain('payroll.submissions.transport.imported.detail_unavailable 2')
  })
})
