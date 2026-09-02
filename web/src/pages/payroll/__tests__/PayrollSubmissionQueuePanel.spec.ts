import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  submissionQueue: vi.fn(),
  dispatchSubmissionBatch: vi.fn(),
  detectPayrollChangesForCompany: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  PAYROLL_QUEUE_BATCH_SIZE: 25,
  payrollApi: {
    submissionQueue: m.submissionQueue,
    dispatchSubmissionBatch: m.dispatchSubmissionBatch,
    detectPayrollChangesForCompany: m.detectPayrollChangesForCompany,
  },
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

function mountPanel() {
  return mount(PayrollSubmissionQueuePanel, {
    props: { environment: 'test' },
    global: { stubs: { EnvironmentSwitch: true, PaginationBar: true, EmptyState: true } },
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
