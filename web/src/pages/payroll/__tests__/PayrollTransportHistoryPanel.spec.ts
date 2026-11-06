import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  jmhzTransportHistory: vi.fn(),
  pollJmhzTransportAttempt: vi.fn(),
  closeJmhzTransportAttempt: vi.fn(),
  reverifyJmhzProtocol: vi.fn(),
  cancelJmhzSubmission: vi.fn(),
  jmhzCorrectableComponents: vi.fn(),
  cancelJmhzSubmissionComponents: vi.fn(),
  jmhzZeroAmendmentCandidates: vi.fn(),
  freezeJmhzZeroAmendment: vi.fn(),
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
  testAllowed: true,
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzTransportHistory: m.jmhzTransportHistory,
    pollJmhzTransportAttempt: m.pollJmhzTransportAttempt,
    closeJmhzTransportAttempt: m.closeJmhzTransportAttempt,
    reverifyJmhzProtocol: m.reverifyJmhzProtocol,
    cancelJmhzSubmission: m.cancelJmhzSubmission,
    jmhzCorrectableComponents: m.jmhzCorrectableComponents,
    cancelJmhzSubmissionComponents: m.cancelJmhzSubmissionComponents,
    jmhzZeroAmendmentCandidates: m.jmhzZeroAmendmentCandidates,
    freezeJmhzZeroAmendment: m.freezeJmhzZeroAmendment,
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
  useAuthStore: () => ({
    canWrite: m.canWrite,
    get submissionTestEnvironmentAllowed() { return m.testAllowed },
  }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    te: () => true,
    tm: (key: string) => [`${key}.0`],
    rt: (message: unknown) => String(message),
    locale: { value: 'cs' },
  }),
}))

import PayrollTransportHistoryPanel from '@/pages/payroll/PayrollTransportHistoryPanel.vue'

/** Hlášení, které opustilo aplikaci datovkou a žádný pokus tedy nemá. */
function dispatched(overrides: Record<string, unknown> = {}) {
  return {
    submission_id: 70,
    agenda_code: 'JMHZ25',
    submission_kind: 'regular',
    submission_status: 'partially_accepted',
    corrects_submission_id: null,
    period_start: '2026-07-01',
    period_end: '2026-07-31',
    created_at: '2026-08-10 08:00:00',
    outbox_id: 12,
    outbox_dispatch_state: 'sent',
    outbox_acceptance_state: 'unknown',
    outbox_external_message_id: '1752953337',
    outbox_correlation_reference: 'JMHZ25-20260810-SYNTETICKA',
    outbox_recipient_box_id: '9tsaf6s',
    outbox_sent_at: '2026-08-10 09:00:00',
    outbox_delivered_at: null,
    outbox_receipt_attached_at: null,
    ...overrides,
  }
}

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
    m.testAllowed = true
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

    expect(m.jmhzTransportHistory).toHaveBeenCalledWith(
      'production',
      { limit: 25, offset: 0 },
      // Rychlý filtr období; nevyplněný znamená „bez omezení".
      { year: null, month: null },
    )
    const group = wrapper.get('[data-test="transport-group-70"]')
    expect(group.text()).toContain('payroll.submissions.transport.group.attempts 2')
    // Období se ukazuje v lidském tvaru, ne v ISO — hledá se proto „01. 07. 2026".
    expect(group.text()).toContain('01. 07. 2026')
    const numbers = group.findAll('[data-test^="transport-attempt-"]')
      .map(node => node.attributes('data-test'))
    expect(numbers).toEqual(['transport-attempt-3', 'transport-attempt-2'])
    expect(wrapper.find('[data-test="transport-group-71"]').exists()).toBe(true)
  })

  // Ostré odeslání se ptá; dialog se teleportuje do <body>, ne do wrapperu.
  async function confirmProductionSend(): Promise<void> {
    const buttons = document.querySelectorAll<HTMLButtonElement>(
      '[data-test="production-send-confirm-yes"]',
    )
    const button = buttons[buttons.length - 1]
    expect(button).toBeDefined()
    button!.click()
    await flushPromises()
  }

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
    expect(m.sendJmhzTransport).not.toHaveBeenCalled()
    await confirmProductionSend()

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
    expect(m.enqueueJmhzIsds).not.toHaveBeenCalled()
    await confirmProductionSend()

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
    await confirmProductionSend()

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

  it('hlášení v dílčích balících po přerušeném odeslání doposílá jen přes VREP a ukáže průběh', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [
        attempt({ id: 5, submission_id: 92, attempt_no: 2, status: 'failed', sent_at: null, package_ordinal: 2, package_count: 2 }),
        attempt({ id: 4, submission_id: 92, attempt_no: 1, package_ordinal: 1, package_count: 2 }),
      ],
      ready_submissions: [{
        submission_id: 92,
        agenda_code: 'JMHZ',
        submission_kind: 'regular',
        submission_status: 'submitted',
        corrects_submission_id: null,
        period_start: '2026-07-01',
        period_end: '2026-07-31',
        created_at: '2026-08-26 07:00:00',
        outbox_id: null,
        outbox_dispatch_state: null,
        outbox_acceptance_state: null,
        outbox_external_message_id: null,
        package_count: 2,
        packages_sent: 1,
      }],
    })
    m.sendJmhzTransport.mockRejectedValueOnce(new Error('VREP nedostupné'))
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    const packages = wrapper.get('[data-test="transport-ready-packages-92"]').text()
    expect(packages).toContain('payroll.submissions.transport.ready.packages.progress')
    expect(packages).toContain('payroll.submissions.transport.ready.packages.interrupted')
    expect(wrapper.find('[data-test="transport-ready-isds-92"]').exists()).toBe(false)
    const send = wrapper.get('[data-test="transport-ready-vrep-92"]')
    expect(send.text()).toBe('payroll.submissions.transport.ready.packages.send_remaining')
    expect(wrapper.get('[data-test="transport-attempt-package-4"]').text())
      .toContain('payroll.submissions.transport.attempt_package')
    expect(wrapper.get('[data-test="transport-attempt-package-5"]').text()).toContain('2')

    await send.trigger('click')
    await flushPromises()
    await confirmProductionSend()

    expect(m.sendJmhzTransport).toHaveBeenCalledWith(92, '1234567890', 'production', expect.any(String))
    // Část balíků mohla odejít, než odeslání spadlo: průběh se načte znovu.
    expect(m.jmhzTransportHistory).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('VREP nedostupné')
  })

  it('nerozdělené podání průběh balíků neukazuje', async () => {
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test^="transport-ready-packages-"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-attempt-package-1"]').exists()).toBe(false)
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

  /**
   * Pokus „možná doručeno": u pokusu stojí celý postup (kde hledat protokol,
   * tlačítko Načíst protokol, potvrzení opakování), smazání se nenabízí a
   * načtení protokolu otevře výběr souboru téže obrazovky.
   */
  it('u pokusu „možná doručeno" nabídne dohledání protokolu a potvrzení, ne smazání', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        id: 6,
        submission_id: 71,
        status: 'possibly_delivered',
        submission_status: 'ready',
        correlation_reference: null,
        sent_at: null,
        error_code: 'jmhz_vrep_response_lost',
        error_message: 'Požadavek na VREP odešel, ale odpověď nedorazila.',
      })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel, {
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    const notice = wrapper.get('[data-test="possibly-delivered-71"]')
    expect(notice.text()).toContain('payroll.transport_delivery.possibly_where')
    expect(notice.find('[data-test="possibly-delivered-confirm-open-71"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="transport-delete-6"]').exists()).toBe(false)

    const input = wrapper.get('[data-test="transport-import-input"]').element as HTMLInputElement
    const click = vi.spyOn(input, 'click')
    await notice.get('[data-test="possibly-delivered-import-71"]').trigger('click')
    expect(click).toHaveBeenCalled()
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
          {
            code: 40326,
            message: 'V systému nesmí existovat více řádných podání za jedno rozhodné období.',
            origin: 'cjmhz',
            control_id: 326,
            form_guid: null,
            ik_mpsv: null,
            id_ppv: null,
            control: null,
            remediation: {
              code: 'jmhz_protocol_regular_submission_duplicate',
              kind: 'correction',
              field: null,
              source: 'cssz_faq_2026_06_09',
              empty_attribute_ids: [],
            },
          },
        ],
      },
    })

    const wrapper = mount(PayrollTransportHistoryPanel, {
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
    })
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
    expect(wrapper.find('[data-test="transport-report-remediation-1-1"]').exists()).toBe(false)

    // Chyba s doloženou nápravou ukáže vysvětlení, kroky a proklik.
    const remediation = wrapper.get('[data-test="transport-report-remediation-1-2"]')
    expect(remediation.text()).toContain('payroll.jmhz_gate.codes.jmhz_protocol_regular_submission_duplicate')
    expect(remediation.text())
      .toContain('payroll.jmhz_protocol_help.steps.jmhz_protocol_regular_submission_duplicate.0')
    expect(remediation.text()).toContain('payroll.jmhz_protocol_help.source.cssz_faq_2026_06_09')
    expect(wrapper.get('[data-test="transport-report-remediation-1-2-link"]').text())
      .toContain('payroll.jmhz_gate.remediation.correction')
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
      attempts: [
        attempt({ id: 8, status: 'completed' }),
        attempt({ id: 9, status: 'awaiting_protocol' }),
      ],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-close-8"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-poll-9"]').exists()).toBe(true)
  })

  /**
   * Odeslaný a uzavřený pokus je doklad o ostrém podání: smazat ho nejde a na
   * stav uzavřené transakce se znovu neptá (ledger ji znovu neotevře).
   */
  it('u odeslaného uzavřeného pokusu nenabídne smazání ani doptání', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        id: 2,
        attempt_no: 1,
        status: 'completed',
        closed_at: '2026-10-01 10:05:00',
        can_delete: false,
      })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-delete-2"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-poll-2"]').exists()).toBe(false)
  })

  it('smazání nabídne jen u pokusu, který server označí jako smazatelný', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [
        attempt({ id: 3, status: 'failed', correlation_reference: null, sent_at: null, can_delete: true }),
        attempt({ id: 4, status: 'awaiting_protocol', can_delete: false }),
        attempt({ id: 5, status: 'failed', correlation_reference: null, sent_at: null }),
      ],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-delete-3"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="transport-delete-4"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-delete-5"]').exists()).toBe(false)
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

    expect(m.jmhzTransportHistory).toHaveBeenLastCalledWith(
      'test',
      { limit: 25, offset: 0 },
      { year: null, month: null },
    )
    expect(wrapper.get('[data-test="transport-environment-note"]').text())
      .toContain('payroll.submissions.transport.environment.test_note')
  })

  describe('mimo vývojovou instalaci', () => {
    beforeEach(() => {
      m.testAllowed = false
    })

    it('nevykreslí přepínač prostředí ani poznámku o ostrém provozu', async () => {
      const wrapper = mount(PayrollTransportHistoryPanel)
      await flushPromises()

      expect(wrapper.find('[data-test="transport-environment"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="transport-environment-test"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="transport-environment-production"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="transport-environment-note"]').exists()).toBe(false)
    })

    // Produkce 6.28.0: volba `test` (z přepínače nebo uloženého stavu) poslala
    // dotaz do testu, server ho odmítl a podání se neukázala vůbec.
    it('s předanou volbou test načte produkci a podání ukáže', async () => {
      const wrapper = mount(PayrollTransportHistoryPanel, { props: { environment: 'test' } })
      await flushPromises()

      expect(m.jmhzTransportHistory).toHaveBeenCalled()
      for (const call of m.jmhzTransportHistory.mock.calls) expect(call[0]).toBe('production')
      for (const call of m.jmhzImportedProtocols.mock.calls) expect(call[0]).toBe('production')
      expect(wrapper.emitted('update:environment')).toEqual([['production']])
      expect(wrapper.find('[data-test="transport-load-error"]').exists()).toBe(false)
      expect(wrapper.text()).toContain('ABC-123-XYZ')
    })
  })

  it('odmítnutí testu serverem nezobrazí jako selhání načtení a načte produkci', async () => {
    m.jmhzTransportHistory.mockImplementation(async (environment: string) => {
      if (environment === 'test') {
        throw {
          response: {
            status: 403,
            data: { error: {
              code: 'submission_test_environment_disabled',
              message: 'Testovací prostředí úřadů je dostupné jen ve vývojové instalaci.',
            } },
          },
        }
      }
      return { environment: 'production', attempts: [attempt()] }
    })

    const wrapper = mount(PayrollTransportHistoryPanel, { props: { environment: 'test' } })
    await flushPromises()

    expect(m.jmhzTransportHistory).toHaveBeenLastCalledWith(
      'production',
      { limit: 25, offset: 0 },
      { year: null, month: null },
    )
    expect(wrapper.find('[data-test="transport-load-error"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ABC-123-XYZ')
  })

  it('pozdě došlou odpověď opuštěného prostředí zahodí', async () => {
    let rejectTest: (reason: unknown) => void = () => {}
    m.jmhzTransportHistory.mockImplementation((environment: string) => environment === 'test'
      ? new Promise((_, reject) => { rejectTest = reject })
      : Promise.resolve({ environment: 'production', attempts: [attempt()] }))

    const wrapper = mount(PayrollTransportHistoryPanel, { props: { environment: 'test' } })
    await flushPromises()
    await wrapper.setProps({ environment: 'production' })
    await flushPromises()
    rejectTest({ response: { data: { error: { message: 'Pozdní chyba testu.' } } } })
    await flushPromises()

    expect(wrapper.find('[data-test="transport-load-error"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ABC-123-XYZ')
  })

  it('N-2: v testu s ostrým VS varuje, ale nezablokuje odeslání', async () => {
    m.employerSettings.mockResolvedValue({
      offices: [{
        id: 42,
        code: 'MAIN',
        name: 'Hlavní účtárna',
        social_security_variable_symbol: '1234567890',
        test_social_security_variable_symbol: '9988776655',
        is_active: true,
      }],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    expect(wrapper.find('[data-test="transport-vs-test-mismatch-warning"]').exists()).toBe(false)

    await wrapper.get('[data-test="transport-environment-test"]').trigger('click')
    await flushPromises()

    // Jednoznačný testovací VS se předvyplní sám, varování tedy nesvítí.
    expect((wrapper.get('[data-test="transport-variable-symbol"]').element as HTMLInputElement).value)
      .toBe('9988776655')
    expect(wrapper.find('[data-test="transport-vs-test-mismatch-warning"]').exists()).toBe(false)

    // Přepis na ostrý VS v testovacím prostředí je stále povolený — je to varování, ne zákaz.
    const input = wrapper.get('[data-test="transport-variable-symbol"]')
    await input.setValue('1234567890')
    await flushPromises()

    expect(wrapper.get('[data-test="transport-vs-test-mismatch-warning"]').text())
      .toContain('payroll.submissions.transport.vs.test_mismatch_warning')
    expect((wrapper.get('[data-test="transport-variable-symbol"]').element as HTMLInputElement).disabled)
      .toBe(false)
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
      .toContain('payroll.submissions.transport.group.period 01. 07. 2026 31. 07. 2026')
    expect(wrapper.get('[data-test="transport-group-71"]').text())
      .toContain('payroll.submissions.transport.group.period 01. 06. 2026 30. 06. 2026')
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
      .toContain('payroll.submissions.transport.group.period 01. 07. 2026 31. 07. 2026')
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

  /*
   * Produkce: za 8/2026 stála nahoře stará chyba (protokol k řádnému podání)
   * a až pod ní přijaté opravné podání. Řádné podání č. 1 navíc dál nabízelo
   * opravu i storno, přestože ho už nahradilo opravné č. 3.
   */
  describe('seskupení podle období', () => {
    function periodScenario() {
      m.jmhzTransportHistory.mockResolvedValue({
        environment: 'production',
        attempts: [],
        dispatched_submissions: [
          dispatched({
            submission_id: 4,
            submission_status: 'accepted',
            period_start: '2026-09-01',
            period_end: '2026-09-30',
            outbox_id: 14,
            outbox_sent_at: '2026-10-08 09:00:00',
            outbox_correlation_reference: 'JMHZ25-SYNT-4',
          }),
          dispatched({
            submission_id: 3,
            submission_kind: 'correction',
            submission_status: 'accepted',
            corrects_submission_id: 1,
            period_start: '2026-08-01',
            period_end: '2026-08-31',
            outbox_id: 13,
            outbox_sent_at: '2026-09-20 10:00:00',
            outbox_correlation_reference: 'JMHZ25-SYNT-3',
          }),
          dispatched({
            submission_id: 1,
            submission_status: 'partially_accepted',
            period_start: '2026-08-01',
            period_end: '2026-08-31',
            outbox_id: 11,
            outbox_sent_at: '2026-09-08 09:00:00',
            outbox_correlation_reference: 'JMHZ25-SYNT-1',
          }),
        ],
      })
      m.jmhzImportedProtocols.mockResolvedValue({
        environment: 'production',
        protocols: [protocol({
          id: 21,
          period_month: 8,
          period_year: 2026,
          correlation_reference: null,
          status_code: 3,
          status_name: 'PartiallyAccepted',
          error_count: 1,
          submitted_at: '2026-09-08T09:00:00+02:00',
        })],
      })
    }

    function order(wrapper: ReturnType<typeof mount>): string[] {
      return wrapper.findAll('[data-test^="transport-period-"], [data-test^="transport-group-"],'
        + ' [data-test^="transport-history-toggle-"], [data-test^="transport-imported-2"]')
        .map(node => node.attributes('data-test') ?? '')
        .filter(value => /^transport-(period|group|history-toggle|imported)-[\d-]+$/.test(value))
    }

    it('nahoře platný stav období, nahrazené podání a jeho protokol sbalené v historii', async () => {
      periodScenario()
      const wrapper = mount(PayrollTransportHistoryPanel)
      await flushPromises()

      expect(order(wrapper)).toEqual([
        'transport-period-2026-09',
        'transport-group-4',
        'transport-period-2026-08',
        'transport-group-3',
        'transport-history-toggle-2026-08',
      ])
      expect(wrapper.get('[data-test="transport-history-toggle-2026-08"]').text())
        .toContain('payroll.submissions.transport.history.toggle 2')
      expect(wrapper.get('[data-test="transport-result-3"]').text())
        .toContain('payroll.submissions.transport.result.accepted')
      expect(wrapper.get('[data-test="transport-result-3"]').classes().join(' ')).toContain('success')
      expect(wrapper.get('[data-test="transport-source-app-3"]').text())
        .toContain('payroll.submissions.transport.source.databox')
      expect(wrapper.get('[data-test="transport-group-3"]').text()).not.toContain('group.attempts')

      await wrapper.get('[data-test="transport-history-toggle-2026-08"]').trigger('click')

      expect(order(wrapper)).toEqual([
        'transport-period-2026-09',
        'transport-group-4',
        'transport-period-2026-08',
        'transport-group-3',
        'transport-history-toggle-2026-08',
        'transport-group-1',
        'transport-imported-21',
      ])
      expect(wrapper.get('[data-test="transport-replaced-1"]').text())
        .toContain('payroll.submissions.transport.history.replaced_corrected 3')
      expect(wrapper.get('[data-test="transport-result-1"]').text())
        .toContain('payroll.submissions.transport.result.partially_accepted')
      expect(wrapper.get('[data-test="transport-imported-21"]').attributes('data-attached-to')).toBe('1')
    })

    it('opravu a storno nabídne jen u platného podání období, ne u nahrazeného', async () => {
      periodScenario()
      const wrapper = mount(PayrollTransportHistoryPanel)
      await flushPromises()
      await wrapper.get('[data-test="transport-history-toggle-2026-08"]').trigger('click')

      const replaced = wrapper.get('[data-test="transport-group-1"]')
      expect(replaced.find('[data-test^="transport-correct-"]').exists()).toBe(false)
      expect(replaced.find('[data-test^="transport-cancel-"]').exists()).toBe(false)
      // Platná karta opravného podání míří s opravou na své řádné podání.
      const current = wrapper.get('[data-test="transport-group-3"]')
      expect(current.find('[data-test="transport-correct-1"]').exists()).toBe(true)
      expect(current.find('[data-test="transport-cancel-1"]').exists()).toBe(true)
    })

    it('protokol, který nejde jednoznačně spárovat, zůstane samostatně', async () => {
      periodScenario()
      m.jmhzImportedProtocols.mockResolvedValue({
        environment: 'production',
        protocols: [protocol({
          id: 22,
          period_month: 8,
          period_year: 2026,
          correlation_reference: null,
          status_name: 'Processing',
          submitted_at: null,
        })],
      })
      const wrapper = mount(PayrollTransportHistoryPanel)
      await flushPromises()

      const card = wrapper.get('[data-test="transport-imported-22"]')
      expect(card.attributes('data-attached-to')).toBeUndefined()
      expect(card.attributes('data-zone')).toBe('current')
    })
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

    // Ledger drží časy v UTC (`UTC_TIMESTAMP()`), panel je ukazuje v pásmu
    // prohlížeče — v srpnu tedy o dvě hodiny později než syrová hodnota.
    const automation = wrapper.get('[data-test="transport-automation-12"]')
    expect(automation.text()).toContain('payroll.submissions.transport.automation.next_poll 11. 08. 2026 12:00')
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
      .toContain('payroll.submissions.transport.automation.closed 11. 08. 2026 12:05')
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

  /*
   * Storno jen vybraných vztahů: routa i klient existovaly, obrazovka ne.
   * Účetní vybírá lidi podle jména, potvrzuje dopad a teprve pak vznikne
   * opravné hlášení se stornujícími formuláři.
   */
  it('storno vybraných vztahů nabídne lidi podle jména a potvrzení dopadu', async () => {
    m.jmhzCorrectableComponents.mockResolvedValue({
      environment: 'production',
      submission_id: 70,
      components: [
        { form_guid: 'AAAA0001', person_external_identifier: '1000000001', employment_external_identifier: '2000000000000000000001', employee_name: 'Jana Syntetická' },
        { form_guid: 'AAAA0002', person_external_identifier: '1000000019', employment_external_identifier: '2000000000000000000002', employee_name: 'Petr Syntetický' },
      ],
    })
    m.cancelJmhzSubmissionComponents.mockResolvedValue({
      submission_id: 93,
      part_id: 1,
      artifact_id: 2,
      status: 'ready',
      row_version: 3,
      environment: 'production',
      artifact_sha256: 'd'.repeat(64),
      created: true,
      submission_kind: 'correction',
      corrects_submission_id: 70,
      submission_guid: '0195AAAA-1111-7222-8333-BBBBCCCCDDDD',
      variable_symbol: '1234567890',
      month: 7,
      year: 2026,
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-cancel-70"]').trigger('click')
    await wrapper.get('[data-test="transport-cancel-mode-components-70"]').trigger('change')
    await flushPromises()

    expect(m.jmhzCorrectableComponents).toHaveBeenCalledWith(70, 'production')
    const panel = wrapper.get('[data-test="transport-cancel-components-70"]')
    expect(panel.text()).toContain('Petr Syntetický')
    const submit = wrapper.get('[data-test="transport-cancel-components-submit-70"]')
    expect(submit.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="transport-cancel-component-2000000000000000000002"] input').setValue(true)
    expect(wrapper.get('[data-test="transport-cancel-components-submit-70"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="transport-cancel-components-impact-70"]').setValue(true)
    await wrapper.get('[data-test="transport-cancel-components-submit-70"]').trigger('click')
    await flushPromises()

    expect(m.cancelJmhzSubmissionComponents).toHaveBeenCalledWith(70, 'production', ['AAAA0002'])
    expect(m.cancelJmhzSubmission).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="transport-success"]').text())
      .toContain('payroll.jmhz_gate.cancel_components.frozen 93')
  })

  /**
   * Anulace měsíce nulovým opravným hlášením: jen vztahy, které server
   * pustí, a teprve po výslovném potvrzení situace i dopadu.
   */
  it('anulace měsíce vyžaduje potvrzení situace i dopadu a nabídne jen způsobilé vztahy', async () => {
    m.jmhzZeroAmendmentCandidates.mockResolvedValue({
      environment: 'production',
      submission_id: 70,
      submission_status: 'accepted',
      situation: 'amendment_unreportable_month',
      month: 7,
      year: 2026,
      cancellation_allowed: false,
      forms: [
        {
          form_guid: 'AAAA0001', person_external_identifier: '1000000001', employment_external_identifier: '2000000000000000000001',
          employment_id: 11, employee_id: 21, employee_name: 'Jana Syntetická', employment_start: '2026-01-01', employment_end: '2026-06-30',
          annullable: true, blocker: null, amendments: [],
        },
        {
          form_guid: 'AAAA0002', person_external_identifier: '1000000019', employment_external_identifier: '2000000000000000000002',
          employment_id: 12, employee_id: 22, employee_name: 'Petr Syntetický', employment_start: '2026-01-01', employment_end: null,
          annullable: false, blocker: { code: 'jmhz_zero_amendment_employment_active_in_month', message: 'trvá' }, amendments: [],
        },
      ],
    })
    m.freezeJmhzZeroAmendment.mockResolvedValue({
      submission_id: 94, part_id: 1, artifact_id: 2, status: 'ready', row_version: 3, environment: 'production',
      artifact_sha256: 'e'.repeat(64), created: true, submission_kind: 'correction', corrects_submission_id: 70,
      submission_guid: '0195AAAA-1111-7222-8333-BBBBCCCCDDDD', variable_symbol: '1234567890', month: 7, year: 2026,
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-cancel-70"]').trigger('click')
    await wrapper.get('[data-test="transport-cancel-mode-annul-70"]').trigger('change')
    await flushPromises()

    expect(m.jmhzZeroAmendmentCandidates).toHaveBeenCalledWith(70, 'production')
    expect(wrapper.get('[data-test="transport-annul-form-2000000000000000000002"] input').attributes('disabled')).toBeDefined()
    const submit = () => wrapper.get('[data-test="transport-annul-submit-70"]')
    await wrapper.get('[data-test="transport-annul-form-2000000000000000000001"] input').setValue(true)
    expect(submit().attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="transport-annul-situation-70"]').setValue(true)
    expect(submit().attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="transport-annul-impact-70"]').setValue(true)
    await submit().trigger('click')
    await flushPromises()

    expect(m.freezeJmhzZeroAmendment).toHaveBeenCalledWith(70, 'production', ['AAAA0001'])
    expect(m.cancelJmhzSubmissionComponents).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="transport-success"]').text())
      .toContain('payroll.jmhz_gate.zero_amendment.frozen 94')
  })

  it('obsahová oprava ukáže vztahy, které teď opravit nejde', async () => {
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
        protocol_error_count: 0,
        action: 'correct_values',
      }],
      blocked_forms: [{
        code: 'jmhz_average_hourly_earning_missing',
        entity_type: 'employment',
        entity_id: 12,
        attribute_ids: ['10345'],
        reason: 'Chybí ověřený průměrný hodinový výdělek.',
      }],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-correct-70"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="transport-correct-blocked"]').text())
      .toContain('payroll.jmhz_gate.correction_blocked.title')
  })

  it('obsahová oprava rozliší opravu přijatého a doplnění odmítnutého formuláře', async () => {
    const components = [{
      employee_name: 'Jana Syntetická',
      person_external_identifier: '1234567890',
      employment_external_identifier: '987654321',
      effective_state: 'accepted',
      protocol_error_count: 0,
      action: 'correct_values',
    }, {
      employee_name: 'Petr Syntetický',
      person_external_identifier: '1234567891',
      employment_external_identifier: '987654322',
      effective_state: 'rejected',
      protocol_error_count: 1,
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
        // Hlášení odeslané datovkou nemá uzavřený dotazovací pokus: protokol
        // přijde do schránky a VREP se na nic nedoptá. Rozhoduje proto stav
        // podání, který server zapíše jen z ověřeného protokolu.
        status: 'awaiting_protocol',
        completed_at: null,
        submission_status: 'partially_accepted',
      })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    await wrapper.get('[data-test="transport-correct-70"]').trigger('click')
    await flushPromises()
    expect(m.jmhzContentCorrectionCandidates).toHaveBeenCalledWith(70, 125, 'production')
    // UX: proaktivní varování o tvrdém termínu 20. dne, ne až po odmítnutí ČSSZ.
    expect(wrapper.get('[data-test="transport-correction-deadline-hint"]').text())
      .toContain('payroll.submissions.transport.correction.deadline_hint')
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

  /**
   * Označení „chyba v protokolu" musí fungovat i u hlášení odeslaného datovkou.
   *
   * Dřív se plnilo výhradně z reportu doptání na VREP. Podání poslané datovkou
   * žádný dotazovací pokus nemá, takže u něj nesvítilo nic a účetní musela mezi
   * padesáti zaměstnanci hledat odmítnutý formulář ručně — přestože výsledky
   * formulářů včetně chyb aplikace v evidenci má.
   */
  it('označí vytknuté vztahy i bez dotazovacího pokusu', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [],
      dispatched_submissions: [dispatched()],
    })
    m.jmhzContentCorrectionCandidates.mockResolvedValue({
      environment: 'production',
      submission_id: 70,
      preparation_id: 125,
      submission_guid: '0195AAAA-1111-7222-8333-BBBBCCCCDDDD',
      document_sha256: 'f'.repeat(64),
      forms: [{
        employee_name: 'Jana Syntetická',
        person_external_identifier: '1234567890',
        employment_external_identifier: '987654321',
        effective_state: 'accepted',
        protocol_error_count: 0,
        action: 'correct_values',
      }, {
        employee_name: 'Petr Syntetický',
        person_external_identifier: '1234567891',
        employment_external_identifier: '987654322',
        effective_state: 'rejected',
        protocol_error_count: 1,
        action: 'complete_form',
      }],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-correct-70"]').trigger('click')
    await flushPromises()

    // Žádné doptání neproběhlo, přesto je vytknutý vztah vidět.
    expect(m.pollJmhzTransportAttempt).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="transport-correct-component-987654322"]').text())
      .toContain('payroll.submissions.transport.correction.flagged_by_protocol')
    expect(wrapper.get('[data-test="transport-correct-component-987654321"]').text())
      .not.toContain('payroll.submissions.transport.correction.flagged_by_protocol')

    // A tlačítko „Vybrat označené protokolem" vybere právě jeho.
    await wrapper.get('[data-test="transport-correct-select-errors"]').trigger('click')
    await flushPromises()
    const flagged = wrapper.get('[data-test="transport-correct-component-987654322"] input')
    expect((flagged.element as HTMLInputElement).checked).toBe(true)
    const clean = wrapper.get('[data-test="transport-correct-component-987654321"] input')
    expect((clean.element as HTMLInputElement).checked).toBe(false)
  })

  /**
   * Hlášení odeslané datovkou nemá žádný dotazovací pokus, a přehled se přitom
   * skládal výhradně z pokusů. Zmizelo tedy z obrazovky úplně a s ním storno
   * i oprava, přestože protokol o výsledku už ležel v datové schránce.
   */
  it('ukáže hlášení odeslané datovkou, které nemá žádný pokus', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [],
      dispatched_submissions: [dispatched()],
    })
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-group-70"]').exists()).toBe(true)

    const detail = wrapper.get('[data-test="transport-dispatched-70"]').text()
    expect(detail).toContain('9tsaf6s')
    expect(detail).toContain('1752953337')
    expect(detail).toContain('JMHZ25-20260810-SYNTETICKA')

    // Obě akce, které na kartě podání visí, musí být dostupné i tudy.
    expect(wrapper.find('[data-test="transport-correct-70"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="transport-cancel-70"]').exists()).toBe(true)
  })

  /** Podání s pokusem se ukazuje z ledgeru pokusů, ne dvakrát. */
  it('nezdvojí kartu, když podání má pokus i odchozí zprávu', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ submission_status: 'partially_accepted' })],
      dispatched_submissions: [dispatched()],
    })
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.findAll('[data-test="transport-group-70"]')).toHaveLength(1)
    expect(wrapper.get('[data-test="transport-dispatched-70"]').text()).toContain('1752953337')
    expect(wrapper.find('[data-test="transport-attempt-1"]').exists()).toBe(true)
  })

  /**
   * Konečný protokol pozná stav PODÁNÍ, ne uzavřený dotazovací pokus. Hlášení
   * odeslané datovou schránkou žádný uzavřený pokus mít nebude — protokol chodí
   * do schránky — a přesto je opravitelné. Dokud se čekalo na `completed`,
   * nabízelo částečně přijaté hlášení storno, ale opravu ne.
   */
  it('opravu nabídne podle stavu podání, ne podle uzavřeného pokusu', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ submission_status: 'submitted' })],
    })
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-correct-70"]').exists()).toBe(false)

    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        submission_status: 'partially_accepted',
        status: 'awaiting_protocol',
        completed_at: null,
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
      protocol_error_count: 0,
      action: 'correct_values',
    }, {
      employee_name: 'Petr Syntetický',
      person_external_identifier: '1234567891',
      employment_external_identifier: '987654322',
      effective_state: 'rejected',
      protocol_error_count: 1,
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

  /**
   * Prohlížeč umí schránku zakázat politikou. Tlačítko, po kterém se mlčky
   * nic nestane, vypadá jako rozbitá aplikace — a uživatel do dotazu na ČSSZ
   * vloží to, co měl ve schránce předtím.
   */
  it('když schránka selže, řekne to a pošle uživatele označit text ručně', async () => {
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-copy-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="transport-copy-failed-1"]').text())
      .toContain('payroll.submissions.transport.copy_failed')
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

  describe('znovu ověření uloženého protokolu', () => {
    function unverifiedAttempt() {
      return attempt({
        id: 8,
        status: 'completed',
        submission_status: 'submitted',
        completed_at: '2026-08-11 10:00:00',
        unverified_receipt_id: 55,
      })
    }

    it('bez neověřeného protokolu tlačítko nenabídne', async () => {
      const wrapper = mount(PayrollTransportHistoryPanel)
      await flushPromises()

      expect(wrapper.find('[data-test="transport-reverify-1"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="transport-unverified-note-1"]').exists()).toBe(false)
    })

    it('ověřený protokol znovu načte přehled a ukáže nový stav podání', async () => {
      m.jmhzTransportHistory
        .mockResolvedValueOnce({ environment: 'production', attempts: [unverifiedAttempt()] })
        .mockResolvedValue({
          environment: 'production',
          attempts: [attempt({
            id: 8,
            status: 'completed',
            submission_status: 'accepted',
            unverified_receipt_id: null,
          })],
        })
      m.reverifyJmhzProtocol.mockResolvedValue({
        outcome: 'verified',
        verified: true,
        receipt_id: 55,
        verified_receipt_id: 56,
        remote_status: 'accepted',
        submission_id: 70,
        submission_status: 'accepted',
        code: null,
        message: null,
      })

      const wrapper = mount(PayrollTransportHistoryPanel)
      await flushPromises()

      expect(wrapper.get('[data-test="transport-unverified-note-8"]').text())
        .toContain('payroll.submissions.transport.reverify.note')
      await wrapper.get('[data-test="transport-reverify-8"]').trigger('click')
      await flushPromises()

      expect(m.reverifyJmhzProtocol).toHaveBeenCalledWith(70, 55, 'production')
      expect(m.jmhzTransportHistory).toHaveBeenCalledTimes(2)
      const result = wrapper.get('[data-test="transport-reverify-result-8"]')
      expect(result.text()).toContain('payroll.submissions.transport.reverify.verified')
      expect(result.text()).toContain('payroll.submissions.overview.status.accepted')
      expect(wrapper.find('[data-test="transport-reverify-8"]').exists()).toBe(false)
    })

    it('neověřitelný protokol ukáže důvod a podání nechá být', async () => {
      m.jmhzTransportHistory.mockResolvedValue({
        environment: 'production',
        attempts: [unverifiedAttempt()],
      })
      m.reverifyJmhzProtocol.mockResolvedValue({
        outcome: 'failed',
        verified: false,
        receipt_id: 55,
        verified_receipt_id: null,
        remote_status: null,
        submission_id: 70,
        submission_status: 'submitted',
        code: 'jmhz_protocol_signature_invalid',
        message: 'Podpis protokolu nesouhlasí.',
      })

      const wrapper = mount(PayrollTransportHistoryPanel)
      await flushPromises()
      await wrapper.get('[data-test="transport-reverify-8"]').trigger('click')
      await flushPromises()

      expect(m.jmhzTransportHistory).toHaveBeenCalledTimes(1)
      const result = wrapper.get('[data-test="transport-reverify-result-8"]')
      expect(result.text()).toContain('payroll.submissions.transport.reverify.failed')
      expect(result.text()).toContain('Podpis protokolu nesouhlasí.')
      expect(result.text()).toContain('jmhz_protocol_signature_invalid')
      expect(wrapper.find('[data-test="transport-reverify-8"]').exists()).toBe(true)
    })

    it('stejně jako Zjistit stav je dostupné i v režimu jen pro čtení', async () => {
      m.canWrite.mockReturnValue(false)
      m.jmhzTransportHistory.mockResolvedValue({
        environment: 'production',
        attempts: [unverifiedAttempt()],
      })

      const wrapper = mount(PayrollTransportHistoryPanel)
      await flushPromises()

      expect(wrapper.find('[data-test="transport-reverify-8"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="transport-close-8"]').exists()).toBe(false)
    })

    it('chybu požadavku ukáže jako chybu akce', async () => {
      m.jmhzTransportHistory.mockResolvedValue({
        environment: 'production',
        attempts: [unverifiedAttempt()],
      })
      m.reverifyJmhzProtocol.mockRejectedValue({})

      const wrapper = mount(PayrollTransportHistoryPanel)
      await flushPromises()
      await wrapper.get('[data-test="transport-reverify-8"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="transport-reverify-result-8"]').exists()).toBe(false)
      expect(wrapper.text()).toContain('payroll.submissions.transport.reverify.request_failed')
    })
  })
})
