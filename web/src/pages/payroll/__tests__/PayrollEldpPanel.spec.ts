import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  peoplePage: vi.fn(),
  person: vi.fn(),
  eldpStatement: vi.fn(),
  prepareEldp: vi.fn(),
  completeEldp: vi.fn(),
  submissionDetail: vi.fn(),
  downloadSubmissionArtifact: vi.fn(),
  searchDocuments: vi.fn(),
  canWrite: true,
  canRead: true,
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    peoplePage: m.peoplePage,
    person: m.person,
    eldpStatement: m.eldpStatement,
    prepareEldp: m.prepareEldp,
    completeEldp: m.completeEldp,
    submissionDetail: m.submissionDetail,
    downloadSubmissionArtifact: m.downloadSubmissionArtifact,
  },
}))

vi.mock('@/api/documents', () => ({
  documentsApi: { search: m.searchDocuments },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: () => m.canWrite,
    canRead: () => m.canRead,
  }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('@/components/ui/SearchableSelect.vue', () => ({
  default: {
    name: 'SearchableSelect',
    props: ['modelValue', 'options'],
    emits: ['update:modelValue'],
    template: '<select role="combobox" />',
  },
}))

vi.mock('@/components/payroll/PayrollPersonSearchSelect.vue', () => ({
  default: {
    name: 'PayrollPersonSearchSelect',
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template: '<select data-test="person-search" role="combobox" />',
  },
}))

import PayrollEldpPanel from '../PayrollEldpPanel.vue'

function setup(): void {
  vi.clearAllMocks()
  m.canWrite = true
  m.canRead = true
  m.peoplePage.mockResolvedValue({ items: [], total: 0, limit: 25, offset: 0 })
  m.person.mockResolvedValue({
    id: 11,
    employments: [{
      id: 101,
      employee_id: 11,
      code: 'eldp-synthetic',
      start_date: '2025-01-01',
      end_date: null,
    }],
  })
  m.eldpStatement.mockResolvedValue({ statement: null, supported: {}, manual_completion: null })
  m.prepareEldp.mockResolvedValue({
    statement_id: 5,
    created: true,
    statement_kind: 'annual',
    section_count: 1,
    insurance_days: 365,
    excluded_days_total: 0,
    due_on: '2026-04-30',
    earliest_submission_on: '2026-01-01',
    obligation_id: 7,
    submission_id: 9,
    part_id: 11,
    artifact_id: 13,
    submission_status: 'prepared',
    xml_sha256: 'a'.repeat(64),
    environment: 'production',
  })
  m.submissionDetail.mockResolvedValue({
    submission: { id: 9 },
    parts: [],
    artifacts: [{ id: 13, mime_type: 'application/xml', artifact_kind: 'payload' }],
    receipts: [],
    issues: [],
    events: [],
  })
  m.downloadSubmissionArtifact.mockResolvedValue(undefined)
  m.searchDocuments.mockResolvedValue([])
  m.completeEldp.mockResolvedValue({
    id: 31,
    statement_id: 5,
    obligation_id: 7,
    authority_status: 'accepted',
    confirmation_document_id: 91,
    confirmation_sha256: 'b'.repeat(64),
    confirmation_byte_size: 42,
    confirmation_mime_type: 'application/pdf',
    authority_reference: 'CSSZ-SYNTHETIC-ACCEPTED',
    confirmed_on: '2026-08-25',
    recorded_by: 1,
    recorded_at: '2026-08-25 10:00:00',
    created: true,
    obligation_status: 'fulfilled',
    obligation_row_version: 4,
    local_submission_status: 'prepared',
    submission_id: 9,
  })
  vi.spyOn(window, 'confirm').mockReturnValue(true)
}

describe('PayrollEldpPanel', () => {
  beforeEach(setup)

  it('používá dark-mode tokeny místo natvrdo bílých ploch formuláře', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    expect(wrapper.html()).not.toContain('bg-white')
    expect(wrapper.get('[data-test="eldp-note"]').classes()).toContain('bg-surface')
  })

  it('nedovolí přípravu bez obou výslovných potvrzení', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    const button = wrapper.get('[data-test="eldp-prepare"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('payroll.eldp.noSendNotice')
  })

  it('vypíše blokátory pojmenované serverem', async () => {
    m.prepareEldp.mockRejectedValue({
      isAxiosError: true,
      response: {
        data: {
          error: {
            code: 'eldp_source_incomplete',
            message: 'Evidenční list nelze sestavit.',
            blockers: [{
              code: 'eldp_month_source_missing',
              message: 'Chybí schválená mzdová revize za březen 2025.',
            }],
          },
        },
      },
    })
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="eldp-blocker"]').text())
      .toContain('březen 2025')
  })

  it('po přípravě hlásí připravené podání, ne odeslané', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepareEldp).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-test="eldp-success"]').text())
      .toContain('payroll.eldp.preparedCreated')
  })

  it('nabídne pouze stažení kontrolního XML a nikdy odeslání', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="eldp-download"]').trigger('click')
    await flushPromises()

    expect(m.submissionDetail).toHaveBeenCalledWith(9)
    expect(m.downloadSubmissionArtifact).toHaveBeenCalledWith(
      9,
      expect.objectContaining({ id: 13 }),
    )
    expect(wrapper.text()).toContain('payroll.eldp.manualCompletionNotice')
    expect(wrapper.find('[data-test="eldp-send"]').exists()).toBe(false)
  })

  it('u výzvy vyžádá datum doručení a předá je serveru', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    await wrapper.get('[data-test="eldp-authority-request"]').setValue(true)
    await flushPromises()

    const date = wrapper.get('[data-test="eldp-authority-request-date"]')
    await date.setValue('2026-08-25')
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepareEldp).toHaveBeenCalledWith(expect.objectContaining({
      requested_by_authority: true,
      authority_request_received_on: '2026-08-25',
    }))
  })

  it('doloží přijetí jen ID firemního DMS dokumentu a odliší je od odeslání', async () => {
    m.eldpStatement.mockResolvedValue({
      statement: {
        id: 5,
        statement_kind: 'annual',
        period_from: '2025-01-01',
        period_to: '2025-12-31',
        section_count: 1,
        insurance_days: 365,
        excluded_days_total: 0,
        deducted_days_total: 0,
        due_on: '2026-04-30',
        earliest_submission_on: '2026-01-01',
        xml_sha256: 'a'.repeat(64),
        payload: {},
      },
      supported: {},
      manual_completion: {
        statement_id: 5,
        obligation_id: 7,
        obligation_status: 'prepared',
        obligation_row_version: 2,
        submission_id: 9,
        local_submission_status: 'prepared',
        evidence: [],
      },
    })
    m.searchDocuments.mockResolvedValue([
      { id: 91, title: 'Potvrzení ČSSZ', original_name: 'potvrzeni.pdf', scope: 'company', deleted_at: null },
      { id: 92, title: 'Soukromá poznámka', original_name: 'poznamka.pdf', scope: 'user', deleted_at: null },
      { id: 93, title: 'Dokument v koši', original_name: 'kos.pdf', scope: 'company', deleted_at: '2026-08-20 10:00:00' },
    ])
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    expect(wrapper.get('[data-test="eldp-authority-status-explanation"]').text())
      .toContain('payroll.eldp.manual.statusExplanation.submitted')
    await wrapper.get('[data-test="eldp-authority-status"]').setValue('accepted')
    await wrapper.get('[data-test="eldp-document-query"]').setValue('potvrzení')
    await wrapper.get('[data-test="eldp-document-search"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-test="eldp-document-option"]')).toHaveLength(1)
    await wrapper.get('[data-test="eldp-document-option"]').trigger('click')
    await wrapper.get('[data-test="eldp-authority-reference"]')
      .setValue('CSSZ-SYNTHETIC-ACCEPTED')
    await wrapper.get('[data-test="eldp-confirmed-on"]').setValue('2026-08-25')
    await wrapper.get('[data-test="eldp-complete"]').trigger('click')
    await flushPromises()

    expect(m.completeEldp).toHaveBeenCalledOnce()
    const [statementId, payload] = m.completeEldp.mock.calls[0]
    expect(statementId).toBe(5)
    expect(payload).toEqual(expect.objectContaining({
      environment: 'production',
      expected_obligation_row_version: 2,
      authority_status: 'accepted',
      confirmation_document_id: 91,
      authority_reference: 'CSSZ-SYNTHETIC-ACCEPTED',
      confirmed_on: '2026-08-25',
    }))
    expect(JSON.stringify(payload)).not.toContain('sha256')
  })
})

async function fillConfirmation(
  wrapper: ReturnType<typeof mount>,
): Promise<void> {
  const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
  wrapper.findComponent({ name: 'PayrollPersonSearchSelect' })
    .vm.$emit('update:modelValue', 11)
  await flushPromises()
  selects[0]!.vm.$emit('update:modelValue', 101)
  await flushPromises()
  await wrapper.get('[data-test="eldp-excluded-confirm"]').setValue(true)
  await wrapper.get('[data-test="eldp-deducted-confirm"]').setValue(true)
  await wrapper.get('[data-test="eldp-note"]')
    .setValue('Syntetické potvrzení evidenčního listu.')
  await flushPromises()
}
