import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  peopleOptions: vi.fn(),
  record: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    statutoryObligationOverview: m.overview,
    peopleOptions: m.peopleOptions,
    recordStatutoryObligationEvidence: m.record,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))

import PayrollStatutoryObligationsPanel from '@/pages/payroll/PayrollStatutoryObligationsPanel.vue'

const SearchableSelectStub = defineComponent({
  props: ['modelValue', 'options'],
  emits: ['update:modelValue'],
  template: `<select :value="modelValue ?? ''" @change="$emit('update:modelValue', Number($event.target.value) || $event.target.value)">
    <option value=""></option>
    <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
  </select>`,
})

function matrix() {
  return {
    environment: 'production',
    period: '2026-08',
    matrix_version: 'mz24-p0.v1',
    matrix_sha256: 'a'.repeat(64),
    agendas: [
      {
        agenda_code: 'NEMPRI',
        replacement_mode: 'partially_replaced',
        capability: 'manual_review',
        transport_capability: 'not_supported',
        evidence_supported: true,
        reason_code: 'nempri_only_partially_in_jmhz',
        workflow_codes: ['submit_in_official_channel', 'record_receipt_evidence'],
      },
      {
        agenda_code: 'HZUPN',
        replacement_mode: 'standalone',
        capability: 'manual_review',
        transport_capability: 'not_supported',
        evidence_supported: true,
        reason_code: 'hzupn_remains_standalone',
        workflow_codes: ['submit_in_official_channel', 'record_receipt_evidence'],
      },
      {
        agenda_code: 'STATUTORY_ACCIDENT_INSURANCE',
        replacement_mode: 'standalone',
        capability: 'manual_review',
        transport_capability: 'not_supported',
        evidence_supported: true,
        reason_code: 'accident_insurance_calculation_output_liability_not_supported',
        workflow_codes: ['calculate_accident_insurance_externally', 'record_payment_evidence'],
      },
    ],
    evidence: [],
  }
}

describe('PayrollStatutoryObligationsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.stubGlobal('crypto', { randomUUID: () => 'ui-idempotency-key' })
    m.overview.mockResolvedValue(matrix())
    m.peopleOptions.mockResolvedValue([{ id: 15, full_name: 'Syntetická Osoba', is_active: true, needs_setup: false }])
    m.record.mockResolvedValue({ created: true, evidence: { id: 1 } })
  })

  it('shows partial NEMPRI, standalone HZUPN and manual accident workflow without transport', async () => {
    const wrapper = mount(PayrollStatutoryObligationsPanel, {
      props: { environment: 'production' },
      global: { stubs: { SearchableSelect: SearchableSelectStub } },
    })
    await flushPromises()

    expect(wrapper.get('[data-test="statutory-agenda-NEMPRI"]').text())
      .toContain('payroll.submissions.statutory.replacement_mode.partially_replaced')
    expect(wrapper.get('[data-test="statutory-agenda-HZUPN"]').text())
      .toContain('payroll.submissions.statutory.replacement_mode.standalone')
    const accident = wrapper.get('[data-test="statutory-agenda-STATUTORY_ACCIDENT_INSURANCE"]')
    expect(accident.text()).toContain('payroll.submissions.statutory.capability.manual_review')
    expect(accident.text()).toContain('payroll.submissions.statutory.transport_not_supported')
    expect(accident.find('button').exists()).toBe(true)
  })

  it('records evidence only after explicit confirmation', async () => {
    const wrapper = mount(PayrollStatutoryObligationsPanel, {
      props: { environment: 'production' },
      global: { stubs: { SearchableSelect: SearchableSelectStub } },
    })
    await flushPromises()
    await wrapper.get('[data-test="statutory-agenda-NEMPRI"] button').trigger('click')

    const selects = wrapper.findAll('select')
    await selects[1]!.setValue('15')
    await wrapper.get('[data-test="statutory-case-reference"]').setValue('EDPN-SYNTH-1')
    await wrapper.get('[data-test="statutory-receipt-reference"]').setValue('CSSZ-SYNTH-1')
    await wrapper.get('[data-test="statutory-completed-on"]').setValue('2026-08-20')
    await wrapper.get('[data-test="statutory-document-id"]').setValue('44')

    const save = wrapper.get('[data-test="statutory-evidence-save"]')
    expect((save.element as HTMLButtonElement).disabled).toBe(true)
    await wrapper.get('[data-test="statutory-confirmation"]').setValue(true)
    expect((save.element as HTMLButtonElement).disabled).toBe(false)
    await save.trigger('click')
    await flushPromises()

    expect(m.record).toHaveBeenCalledWith({
      environment: 'production',
      period: expect.stringMatching(/^[0-9]{4}-[0-9]{2}$/),
      agenda_code: 'NEMPRI',
      employee_id: 15,
      case_reference: 'EDPN-SYNTH-1',
      receipt_reference: 'CSSZ-SYNTH-1',
      completed_on: '2026-08-20',
      document_id: 44,
      manual_submission_confirmed: true,
    }, 'ui-idempotency-key')
  })

  it('records accident-insurance payment without inventing an employee or calculation', async () => {
    const wrapper = mount(PayrollStatutoryObligationsPanel, {
      props: { environment: 'production' },
      global: { stubs: { SearchableSelect: SearchableSelectStub } },
    })
    await flushPromises()
    await wrapper.get('[data-test="statutory-agenda-STATUTORY_ACCIDENT_INSURANCE"] button').trigger('click')

    expect(wrapper.find('[data-test="statutory-employee"]').exists()).toBe(false)
    await wrapper.get('[data-test="statutory-payment-amount"]').setValue('1234,56')
    await wrapper.get('[data-test="statutory-case-reference"]').setValue('SYNTH-Q3-2026')
    await wrapper.get('[data-test="statutory-receipt-reference"]').setValue('SYNTH-PAYMENT-1')
    await wrapper.get('[data-test="statutory-completed-on"]').setValue('2026-08-20')
    await wrapper.get('[data-test="statutory-document-id"]').setValue('45')
    await wrapper.get('[data-test="statutory-confirmation"]').setValue(true)
    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    expect(m.record).toHaveBeenCalledWith({
      environment: 'production',
      period: expect.stringMatching(/^[0-9]{4}-[0-9]{2}$/),
      agenda_code: 'STATUTORY_ACCIDENT_INSURANCE',
      payment_amount: '1234,56',
      case_reference: 'SYNTH-Q3-2026',
      receipt_reference: 'SYNTH-PAYMENT-1',
      completed_on: '2026-08-20',
      document_id: 45,
      manual_payment_confirmed: true,
    }, 'ui-idempotency-key')
  })
})
