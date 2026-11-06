import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  submissionQueue: vi.fn(),
  dispatchSubmissionBatch: vi.fn(),
  detectPayrollChangesForCompany: vi.fn(),
  submissionDetail: vi.fn(),
  downloadSubmissionArtifact: vi.fn(),
  credentials: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  PAYROLL_QUEUE_BATCH_SIZE: 25,
  payrollApi: {
    submissionQueue: m.submissionQueue,
    dispatchSubmissionBatch: m.dispatchSubmissionBatch,
    detectPayrollChangesForCompany: m.detectPayrollChangesForCompany,
    submissionDetail: m.submissionDetail,
    downloadSubmissionArtifact: m.downloadSubmissionArtifact,
  },
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: { credentials: m.credentials, gatewayStartPayroll: vi.fn() },
}))

vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_exception: unknown, fallback: string) => fallback,
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    te: () => true,
    locale: { value: 'cs' },
  }),
}))

import PayrollSubmissionQueuePanel from '@/pages/payroll/PayrollSubmissionQueuePanel.vue'

function item(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    submission_id: 1,
    submission_status: 'ready',
    submission_kind: 'regular',
    submission_channel: 'vrep_apep',
    created_at: '2026-08-01 10:00:00',
    corrects_submission_id: null,
    obligation_id: 1,
    agenda_code: 'PREZEC26',
    subject_type: 'employment',
    subject_reference: 'employment:1',
    subject_label: 'Testovací Zaměstnanec',
    period_start: '2026-07-01',
    period_end: '2026-07-31',
    obligation_kind: 'regular',
    obligation_status: 'open',
    earliest_submission_on: '2026-07-01',
    due_on: '2026-08-20',
    deadline: {
      phase: 'open',
      days_to_due: 10,
      is_action_required: true,
      is_overdue: false,
    },
    dispatch: {
      mode: 'vrep_registration',
      alternate_mode: null,
      default_mode: 'vrep_registration',
      modes: ['vrep_registration'],
      dispatchable: true,
      blocked_reason: null,
    },
    blocking_issue_count: 0,
    attempt: null,
    outbox: null,
    ...overrides,
  }
}

function queueResponse(items: Record<string, unknown>[]): Record<string, unknown> {
  return {
    environment: 'test',
    items,
    total: items.length,
    limit: 100,
    offset: 0,
    agenda_code: null,
    sort: 'due',
    agendas: [{ agenda_code: 'PREZEC26', count: items.length }],
    summary: {
      total: items.length,
      ready: items.filter(entry =>
        (entry.dispatch as { dispatchable: boolean }).dispatchable).length,
      blocked: 0,
      overdue: 0,
    },
  }
}

const IsdsBatchStub = {
  name: 'IsdsBatchSendMethods',
  props: { outboxIds: Array, environment: String, transport: Object, gatewayStart: Function },
  emits: ['sent'],
  template: '<div data-test="isds-batch-stub" />',
}

function jmhzItem(dispatch: Record<string, unknown>): Record<string, unknown> {
  return item({
    submission_id: 5,
    agenda_code: 'JMHZ25',
    dispatch: {
      mode: 'vrep_jmhz',
      alternate_mode: 'isds_payroll',
      dispatchable: true,
      blocked_reason: null,
      ...dispatch,
    },
  })
}

function mountPanel() {
  return mount(PayrollSubmissionQueuePanel, {
    props: { environment: 'test' },
    global: {
      stubs: {
        IsdsBatchSendMethods: IsdsBatchStub,
        EnvironmentSwitch: true,
        PaginationBar: true,
        EmptyState: true,
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
}

describe('PayrollSubmissionQueuePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.submissionQueue.mockResolvedValue(queueResponse([item()]))
    m.dispatchSubmissionBatch.mockResolvedValue({
      environment: 'test',
      results: [{ ok: true, submission_id: 1, dispatched: true, message: 'hotovo' }],
      summary: { requested: 1, sent: 1, failed: 0 },
    })
  })

  /**
   * Bez podpisového certifikátu fronta posílala JMHZ přes VREP a padala na
   * „není zvolený podpisový certifikát". Teď odejde datovkou a hned nabídne
   * výběr způsobu přihlášení k datové schránce.
   */
  it('JMHZ bez certifikátu pošle datovkou a nabídne způsoby přihlášení', async () => {
    m.submissionQueue.mockResolvedValue(queueResponse([
      jmhzItem({ default_mode: 'isds_payroll', modes: ['isds_payroll'] }),
    ]))
    m.dispatchSubmissionBatch.mockResolvedValue({
      environment: 'test',
      results: [{
        ok: true,
        submission_id: 5,
        dispatched: true,
        message: 'zařazeno',
        mode: 'isds_payroll',
        outbox: {
          id: 900,
          created: true,
          recipient: null,
          subject: 'JMHZ',
          transport: { automatic: false, channel: 'manual_upload', reason: null },
        },
      }],
      summary: { requested: 1, sent: 1, failed: 0 },
    })
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="dispatch-mode-fallback"]').text()).toContain('payroll.dispatch_mode.fallback')
    expect(wrapper.find('[data-test="dispatch-mode-select"]').exists()).toBe(false)
    await wrapper.get('[data-test="queue-send"]').trigger('click')
    await flushPromises()

    expect(m.dispatchSubmissionBatch).toHaveBeenCalledWith('test', [
      { submission_id: 5, idempotency_key: expect.any(String), mode: 'isds_payroll' },
    ])
    expect(wrapper.find('[data-test="queue-isds-pending"]').exists()).toBe(true)
    expect(wrapper.getComponent(IsdsBatchStub).props('outboxIds')).toEqual([900])
  })

  it('se zvoleným certifikátem nabídne VREP i datovku a pošle zvolený kanál', async () => {
    m.submissionQueue.mockResolvedValue(queueResponse([
      jmhzItem({ default_mode: 'vrep_jmhz', modes: ['vrep_jmhz', 'isds_payroll'] }),
    ]))
    const wrapper = mountPanel()
    await flushPromises()

    const select = wrapper.get('[data-test="dispatch-mode-select"]')
    expect((select.element as HTMLSelectElement).value).toBe('vrep_jmhz')
    await select.setValue('isds_payroll')
    await wrapper.get('[data-test="queue-send"]').trigger('click')
    await flushPromises()

    expect(m.dispatchSubmissionBatch).toHaveBeenCalledWith('test', [
      { submission_id: 5, idempotency_key: expect.any(String), mode: 'isds_payroll' },
    ])
  })

  it('zprávu čekající v odchozí frontě datovky dá dokončit přímo z řádku', async () => {
    m.submissionQueue.mockResolvedValue(queueResponse([
      jmhzItem({
        default_mode: 'isds_payroll',
        modes: ['isds_payroll'],
        dispatchable: false,
        blocked_reason: 'Podání už čeká v odchozí frontě datové schránky.',
      }),
    ].map(entry => ({ ...entry, outbox: { id: 901, dispatch_state: 'ready', acceptance_state: 'none', correlation_reference: null } }))))
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="queue-isds-continue"]').trigger('click')
    await flushPromises()

    expect(wrapper.getComponent(IsdsBatchStub).props('outboxIds')).toEqual([901])
  })

  /** Q15-28: přihláška agendou REGZEC se nesmí tvářit jako změna v registraci. */
  it('u registrace ukáže akci a den události místo měsíce, kdy jde podat', async () => {
    m.submissionQueue.mockResolvedValue(queueResponse([
      item({
        agenda_code: 'REGZEC25',
        registration_action: 'A1',
        period_start: '2026-08-24',
        period_end: '2026-09-01',
      }),
    ]))
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="queue-registration-action"]').text())
      .toContain('payroll.submissions.queue.registration_action.A1')
    expect(wrapper.text()).not.toContain('2026-08')
  })

  it('jméno vede na kartu zaměstnance a řádek ukáže soubory podání ke stažení', async () => {
    m.submissionQueue.mockResolvedValue(queueResponse([
      item({ submission_id: 5, subject_employee_id: 42 }),
    ]))
    const artifact = { id: 9, artifact_kind: 'request_xml', mime_type: 'application/xml', byte_size: 1200 }
    m.submissionDetail.mockResolvedValue({
      submission: { id: 5 },
      artifacts: [artifact],
      issues: [],
    })
    const wrapper = mountPanel()
    await flushPromises()

    const link = wrapper.get('[data-test="queue-subject-link"]')
    expect(link.text()).toContain('Testovací Zaměstnanec')
    expect(link.attributes('data-to')).toContain('"person":"42"')

    await wrapper.get('[data-test="queue-detail-toggle"]').trigger('click')
    await flushPromises()
    expect(m.submissionDetail).toHaveBeenCalledWith(5)
    await wrapper.get('[data-test="queue-artifact-download"]').trigger('click')
    await flushPromises()
    expect(m.downloadSubmissionArtifact).toHaveBeenCalledWith(5, artifact)
  })

  /**
   * Podání „možná doručeno" nejde odeslat, a fronta u něj proto musí říct
   * celý postup: co se stalo, kde protokol hledat (proklik na Stav odeslání
   * a do datové schránky) a jak opakování vědomě potvrdit.
   */
  it('u podání „možná doručeno" ukáže postup a proklik na načtení protokolu', async () => {
    m.submissionQueue.mockResolvedValue(queueResponse([
      item({
        submission_id: 3,
        agenda_code: 'JMHZ25',
        dispatch: {
          mode: 'vrep_jmhz',
          alternate_mode: null,
          dispatchable: false,
          blocked_reason: 'Podání možná doručeno: požadavek odešel, ale odpověď ČSSZ nedorazila.',
        },
        attempt: {
          id: 9,
          attempt_no: 1,
          status: 'possibly_delivered',
          channel: 'vrep_apep',
          error_code: 'jmhz_vrep_response_lost',
          error_message: 'Odpověď nedorazila.',
          sent_at: null,
          correlation_reference: null,
        },
      }),
    ]))
    const wrapper = mount(PayrollSubmissionQueuePanel, {
      props: { environment: 'test' },
      global: {
        stubs: {
          EnvironmentSwitch: true,
          PaginationBar: true,
          EmptyState: true,
          RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
        },
      },
    })
    await flushPromises()

    const notice = wrapper.get('[data-test="possibly-delivered-3"]')
    expect(notice.text()).toContain('payroll.transport_delivery.possibly_where')
    expect(notice.get('[data-test="possibly-delivered-import-link-3"]').attributes('data-to'))
      .toContain('"tab":"transport"')
    expect(notice.find('[data-test="possibly-delivered-confirm-open-3"]').exists()).toBe(true)
  })

  /**
   * Položka, kterou odeslat nejde, se z fronty NESMÍ ztratit — musí být vidět
   * i s důvodem. Mlčky vynechaná položka je horší než položka s důvodem.
   */
  it('ukáže i to, co odeslat nejde, a napíše proč', async () => {
    m.submissionQueue.mockResolvedValue(queueResponse([
      item({
        submission_id: 2,
        agenda_code: 'ELDP',
        dispatch: {
          mode: 'none',
          alternate_mode: null,
          dispatchable: false,
          blocked_reason: 'Evidenční list aplikace neodesílá.',
        },
      }),
    ]))
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.findAll('[data-test="queue-row"]')).toHaveLength(1)
    expect(wrapper.get('[data-test="queue-blocked-reason"]').text())
      .toContain('Evidenční list aplikace neodesílá.')
    // Vybrat ani odeslat to nejde.
    expect(wrapper.get('[data-test="queue-select-row"]').attributes('disabled'))
      .toBeDefined()
    expect(wrapper.get('[data-test="queue-send"]').attributes('disabled'))
      .toBeDefined()
  })

  it('vybere vše, co lze odeslat, a pošle to jedním úkonem', async () => {
    m.submissionQueue.mockResolvedValue(queueResponse([
      item({ submission_id: 1 }),
      item({ submission_id: 2 }),
      item({
        submission_id: 3,
        dispatch: {
          mode: 'none',
          alternate_mode: null,
          dispatchable: false,
          blocked_reason: 'Nelze.',
        },
      }),
    ]))
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="queue-select-all"]').trigger('change')
    await wrapper.get('[data-test="queue-send-selected"]').trigger('click')
    await flushPromises()

    expect(m.dispatchSubmissionBatch).toHaveBeenCalledTimes(1)
    const [, items] = m.dispatchSubmissionBatch.mock.calls[0]
    // Zablokovaná položka se do dávky nedostane.
    expect(items.map((entry: { submission_id: number }) => entry.submission_id))
      .toEqual([1, 2])
    // Každá položka nese VLASTNÍ idempotenční klíč — sdílený by druhou
    // položku transportu ukázal jako opakování první.
    const keys = items.map((entry: { idempotency_key: string }) => entry.idempotency_key)
    expect(new Set(keys).size).toBe(2)
  })

  /**
   * Sto podání se posílá po porcích, aby žádný požadavek neběžel minuty
   * a nespadl na timeoutu.
   */
  it('rozdělí velkou dávku na porce po 25', async () => {
    const many = Array.from({ length: 60 }, (_, index) => item({ submission_id: index + 1 }))
    m.submissionQueue.mockResolvedValue(queueResponse(many))
    m.dispatchSubmissionBatch.mockImplementation(
      (_environment: string, batch: { submission_id: number }[]) => Promise.resolve({
        environment: 'test',
        results: batch.map(entry => ({
          ok: true,
          submission_id: entry.submission_id,
          dispatched: true,
          message: 'hotovo',
        })),
        summary: { requested: batch.length, sent: batch.length, failed: 0 },
      }),
    )
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="queue-select-all"]').trigger('change')
    await wrapper.get('[data-test="queue-send-selected"]').trigger('click')
    await flushPromises()

    expect(m.dispatchSubmissionBatch).toHaveBeenCalledTimes(3)
    const sizes = m.dispatchSubmissionBatch.mock.calls.map(call => call[1].length)
    expect(sizes).toEqual([25, 25, 10])
    expect(wrapper.get('[data-test="queue-batch-result"]').text())
      .toContain('payroll.submissions.queue.batch_summary 60 0')
  })

  /**
   * JEDNA CHYBA NESMÍ SHODIT DÁVKU: spadlá porce se započítá jako neúspěch
   * a zbytek se pošle dál.
   */
  it('pokračuje v dávce, i když jedna porce spadne', async () => {
    const many = Array.from({ length: 50 }, (_, index) => item({ submission_id: index + 1 }))
    m.submissionQueue.mockResolvedValue(queueResponse(many))
    m.dispatchSubmissionBatch
      .mockRejectedValueOnce(new Error('sit spadla'))
      .mockImplementationOnce(
        (_environment: string, batch: { submission_id: number }[]) => Promise.resolve({
          environment: 'test',
          results: batch.map(entry => ({
            ok: true,
            submission_id: entry.submission_id,
            dispatched: true,
            message: 'hotovo',
          })),
          summary: { requested: batch.length, sent: batch.length, failed: 0 },
        }),
      )
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="queue-select-all"]').trigger('change')
    await wrapper.get('[data-test="queue-send-selected"]').trigger('click')
    await flushPromises()

    // Druhá porce se poslala i po pádu první.
    expect(m.dispatchSubmissionBatch).toHaveBeenCalledTimes(2)
    const result = wrapper.get('[data-test="queue-batch-result"]').text()
    expect(result).toContain('payroll.submissions.queue.batch_summary 25 25')
  })

  it('vypíše jmenovitě, co v dávce selhalo', async () => {
    m.dispatchSubmissionBatch.mockResolvedValue({
      environment: 'test',
      results: [{
        ok: false,
        submission_id: 1,
        dispatched: false,
        message: 'Podání nemá uloženou zmrazenou datovou větu.',
      }],
      summary: { requested: 1, sent: 0, failed: 1 },
    })
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="queue-send"]').trigger('click')
    await flushPromises()

    const result = wrapper.get('[data-test="queue-batch-result"]').text()
    expect(result).toContain('#1')
    expect(result).toContain('Podání nemá uloženou zmrazenou datovou větu.')
  })

  it('spustí kontrolu změn za celou firmu a řekne výsledek', async () => {
    m.detectPayrollChangesForCompany.mockResolvedValue({
      environment: 'test',
      scanned: 120,
      changed: 4,
      skipped: 0,
      created: 4,
      has_more: false,
    })
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="queue-detect-changes"]').trigger('click')
    await flushPromises()

    expect(m.detectPayrollChangesForCompany).toHaveBeenCalledWith('test')
    expect(wrapper.get('[data-test="queue-sweep-result"]').text())
      .toContain('payroll.submissions.queue.sweep_done 120 4')
    // Po detekci se fronta načte znovu — nové povinnosti musí být hned vidět.
    expect(m.submissionQueue).toHaveBeenCalledTimes(2)
  })
})
