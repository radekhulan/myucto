import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  peoplePage: vi.fn(),
  person: vi.fn(),
  eldpStatement: vi.fn(),
  prepareEldp: vi.fn(),
  submissionDetail: vi.fn(),
  downloadSubmissionArtifact: vi.fn(),
  canWrite: true,
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    peoplePage: m.peoplePage,
    person: m.person,
    eldpStatement: m.eldpStatement,
    prepareEldp: m.prepareEldp,
    submissionDetail: m.submissionDetail,
    downloadSubmissionArtifact: m.downloadSubmissionArtifact,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => m.canWrite }),
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
  m.eldpStatement.mockResolvedValue({ statement: null, supported: {} })
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
