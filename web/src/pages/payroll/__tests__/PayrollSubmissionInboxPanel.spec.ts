import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  submissionInbox: vi.fn(),
  acknowledgeSubmissionInboxItem: vi.fn(),
  snoozeSubmissionInboxItem: vi.fn(),
  submissionDetail: vi.fn(),
  downloadSubmissionArtifact: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    submissionInbox: m.submissionInbox,
    acknowledgeSubmissionInboxItem: m.acknowledgeSubmissionInboxItem,
    snoozeSubmissionInboxItem: m.snoozeSubmissionInboxItem,
    submissionDetail: m.submissionDetail,
    downloadSubmissionArtifact: m.downloadSubmissionArtifact,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
  }),
}))

import PayrollSubmissionInboxPanel from '@/pages/payroll/PayrollSubmissionInboxPanel.vue'

function inboxItem(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    id: 101,
    obligation_id: 7,
    submission_id: null,
    agenda_code: 'JMHZ25',
    subject_type: 'office',
    subject_reference: 'payroll_run:8:office:4',
    subject_label: 'mzdová účtárna 4',
    period_start: '2026-09-01',
    period_end: '2026-09-30',
    due_on: '2026-09-13',
    problem_kind: 'due_soon',
    escalation_level: 'due_soon',
    status: 'open',
    snoozed_until: null,
    snooze_reason: null,
    acknowledged_at: null,
    resolved_at: null,
    row_version: 1,
    created_at: '2026-09-01 08:00:00',
    updated_at: '2026-09-01 08:00:00',
    ...overrides,
  }
}

function baseResponse(items: Array<Record<string, unknown>> = []) {
  return {
    environment: 'production',
    status: 'unresolved',
    summary: { total: items.length, open: items.length, acknowledged: 0, snoozed: 0 },
    items,
    total: items.length,
    limit: 25,
    offset: 0,
  }
}

function mountPanel() {
  return mount(PayrollSubmissionInboxPanel, {
    props: { environment: 'production' },
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="to"><slot /></a>' },
      },
    },
  })
}

describe('PayrollSubmissionInboxPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  /**
   * Zadání: „Doslova tam stojí HOZ_2026 a pod tím
   * health_bulk_notification:2026-08:111." Agenda musí dostat lidský název
   * a předmět buď ověřený text, nebo se vůbec nezobrazí — nikdy syrový kód.
   */
  it('nezobrazí surový agenda_code ani surový subject_reference', async () => {
    m.submissionInbox.mockResolvedValue(baseResponse([inboxItem({
      agenda_code: 'JMHZ25',
      subject_reference: 'payroll_run:8:office:4',
      subject_label: 'mzdová účtárna 4',
    })]))

    const wrapper = mountPanel()
    await flushPromises()

    const row = wrapper.get('[data-test="inbox-row"]')
    expect(row.text()).not.toContain('payroll_run:8:office:4')
    expect(row.text()).toContain('mzdová účtárna 4')
    // Mockované `t` vrací klíč beze změny — kód JMHZ25 zná katalog Dalších
    // povinností, takže se přeloží přes jeho vlastní slovník.
    expect(row.text()).toContain('payroll.submissions.statutory.agenda.JMHZ25')
  })

  it('u položky bez ověřeného předmětu neukáže žádný podtitulek', async () => {
    m.submissionInbox.mockResolvedValue(baseResponse([inboxItem({
      agenda_code: 'ELDP',
      subject_reference: 'employment:37',
      subject_label: null,
    })]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="inbox-row"]').text()).not.toContain('employment:37')
  })

  it('bez submission_id nenabídne tlačítko detailu', async () => {
    m.submissionInbox.mockResolvedValue(baseResponse([inboxItem({ submission_id: null })]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="inbox-detail-toggle"]').exists()).toBe(false)
  })

  /**
   * Zadání: „účetní musí mít možnost se podívat, o co jde, než to potvrdí."
   * Detail se rozbaluje INLINE, ne až po odchodu z inboxu.
   */
  it('se submission_id nabídne rozbalitelný detail s artefakty a problémy', async () => {
    m.submissionInbox.mockResolvedValue(baseResponse([inboxItem({ submission_id: 31 })]))
    m.submissionDetail.mockResolvedValue({
      submission: {
        id: 31,
        environment: 'production',
        obligation_id: 7,
        agenda_code: 'JMHZ25',
        subject_type: 'office',
        subject_reference: 'payroll_run:8:office:4',
        subject_label: 'mzdová účtárna 4',
        period_start: '2026-08-01',
        period_end: '2026-08-31',
        submission_kind: 'regular',
        channel: 'isds',
        status: 'correction_required',
        row_version: 1,
        source_revision_id: null,
        corrects_submission_id: null,
        correlation_reference: null,
        submitted_at: null,
        decided_at: null,
        created_at: '2026-09-01 08:00:00',
        updated_at: '2026-09-01 08:00:00',
      },
      parts: [],
      artifacts: [{
        id: 51,
        part_id: null,
        artifact_kind: 'outbound_xml',
        direction: 'outbound',
        mime_type: 'application/xml',
        byte_size: 2048,
        xsd_version: '1.4.3.4',
        catalog_version: null,
        channel: 'isds',
        created_at: '2026-09-01 08:01:00',
      }],
      issues: [{
        id: 61,
        part_id: null,
        severity: 'warning',
        validation_stage: 'catalog',
        issue_code: 'jmhz_xsd_validation_failed',
        entity_type: null,
        entity_reference: null,
        is_resolved: false,
        row_version: 1,
        resolved_at: null,
        created_at: '2026-09-01 08:02:00',
        updated_at: '2026-09-01 08:02:00',
      }],
      receipts: [],
    })

    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="inbox-detail-toggle"]').trigger('click')
    await flushPromises()

    expect(m.submissionDetail).toHaveBeenCalledWith(31)
    const detail = wrapper.get('[data-test="inbox-detail"]')
    expect(detail.text()).toContain('outbound_xml')
    expect(detail.text()).toContain('2.0 kB')
    expect(wrapper.get('[data-test="inbox-issue-message"]').text())
      .toContain('jmhz_xsd_validation_failed')

    await wrapper.get('[data-test="inbox-artifact-download"]').trigger('click')
    await flushPromises()
    expect(m.downloadSubmissionArtifact).toHaveBeenCalledWith(
      31,
      expect.objectContaining({ id: 51, mime_type: 'application/xml' }),
    )

    // Druhé kliknutí detail zase skryje.
    await wrapper.get('[data-test="inbox-detail-toggle"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="inbox-detail"]').exists()).toBe(false)
  })

  it('chybu při načtení detailu ukáže, ne že appka mlčí', async () => {
    m.submissionInbox.mockResolvedValue(baseResponse([inboxItem({ submission_id: 31 })]))
    m.submissionDetail.mockRejectedValue({
      response: { data: { error: { message: 'Podání nebylo nalezeno.' } } },
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="inbox-detail-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="inbox-detail-error"]').text()).toContain('Podání nebylo nalezeno.')
  })
})
