import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import type { EnforcementMonthEvidence, InsolvencyOptions } from '@/api/payrollEnforcement'

const m = vi.hoisted(() => ({
  insolvencyOptions: vi.fn(),
  insolvencyEvidence: vi.fn(),
  saveInsolvencyEvidence: vi.fn(),
  cancelInsolvency: vi.fn(),
  person: vi.fn(),
  documentSearch: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
  warning: vi.fn(),
}))

vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: { person: '3', period: '2026-06' } }),
}))

vi.mock('@/api/payrollEnforcement', () => ({
  payrollEnforcementApi: {
    insolvencyOptions: m.insolvencyOptions,
    insolvencyEvidence: m.insolvencyEvidence,
    saveInsolvencyEvidence: m.saveInsolvencyEvidence,
    cancelInsolvency: m.cancelInsolvency,
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    peoplePage: vi.fn().mockResolvedValue({ items: [], total: 0 }),
    person: m.person,
  },
}))

vi.mock('@/api/documents', () => ({ documentsApi: { search: m.documentSearch } }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => true, canWrite: () => true }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error, warning: m.warning }),
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }),
}))

import PayrollInsolvency from '@/pages/payroll/PayrollInsolvency.vue'

function evidence(overrides: Partial<EnforcementMonthEvidence> = {}): EnforcementMonthEvidence {
  return {
    id: 1,
    employee_id: 3,
    period_start: '2026-06-01',
    claim_register_evidence_complete: false,
    dependants_evidence_complete: false,
    spouse_evidence_complete: false,
    pension_evidence: 'unknown',
    has_multiple_payers: false,
    protected_amount_override_minor_units: null,
    protected_amount_override_verified: false,
    insolvency_mode: 'approved_standard',
    insolvency_decision_verified: true,
    insolvency_recipient_verified: true,
    insolvency_payment_instruction_id: 10,
    insolvency_employment_id: 20,
    insolvency_institution_account_id: 30,
    insolvency_decision_document_id: 40,
    insolvency_payment_instruction_hash: 'a'.repeat(64),
    court_determined_amount_minor_units: null,
    row_version: 2,
    ...overrides,
  }
}

const options: InsolvencyOptions = {
  employments: [{
    id: 20,
    code: 'HPP',
    relation_type: 'employment',
    status: 'active',
    start_date: '2026-01-01',
    actual_start_date: '2026-01-01',
    end_date: null,
  }],
  recipient_accounts: [
    {
      id: 30,
      institution_id: 300,
      institution_code: 'INS-A',
      institution_name: 'Správce A',
      bank_account_masked: '••••/0100',
      currency_code: 'CZK',
      variable_symbol: null,
      specific_symbol: null,
      constant_symbol: null,
      valid_from: '2026-01-01',
      valid_to: null,
      source_kind: 'official_document',
      source_reference: 'synthetic:a',
      verified_on: '2026-01-02',
      row_version: 1,
    },
    {
      id: 31,
      institution_id: 301,
      institution_code: 'INS-B',
      institution_name: 'Správce B',
      bank_account_masked: '••••/0300',
      currency_code: 'CZK',
      variable_symbol: null,
      specific_symbol: null,
      constant_symbol: null,
      valid_from: '2026-01-01',
      valid_to: null,
      source_kind: 'official_document',
      source_reference: 'synthetic:b',
      verified_on: '2026-01-02',
      row_version: 1,
    },
  ],
}

describe('PayrollInsolvency', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.person.mockResolvedValue({ id: 3, full_name: 'Syntetická osoba' })
    m.insolvencyOptions.mockResolvedValue(options)
    m.insolvencyEvidence.mockResolvedValue(evidence())
    m.saveInsolvencyEvidence.mockImplementation(async (_id, _period, payload) => ({
      ...evidence(),
      ...payload,
      row_version: 3,
    }))
    m.cancelInsolvency.mockResolvedValue(evidence({ insolvency_mode: 'none' }))
    m.documentSearch.mockResolvedValue([])
  })

  it('works without an enforcement case and replays the immutable instruction', async () => {
    const wrapper = mount(PayrollInsolvency)
    await flushPromises()

    expect(m.insolvencyOptions).toHaveBeenCalledWith(3, '2026-06')
    await wrapper.get('[data-test="insolvency-save"]').trigger('click')
    await flushPromises()

    expect(m.saveInsolvencyEvidence).toHaveBeenCalledWith(
      3,
      '2026-06',
      expect.objectContaining({ insolvency_payment_instruction_id: 10 }),
    )
  })

  it('drops the old instruction id when the payment target changes', async () => {
    const wrapper = mount(PayrollInsolvency)
    await flushPromises()

    await wrapper.get('[data-test="insolvency-account"]').setValue('31')
    await wrapper.get('[data-test="insolvency-save"]').trigger('click')
    await flushPromises()

    expect(m.saveInsolvencyEvidence).toHaveBeenCalledWith(
      3,
      expect.any(String),
      expect.objectContaining({
        insolvency_institution_account_id: 31,
        insolvency_payment_instruction_id: null,
      }),
    )
  })

  it('reloads evidence and options after a row-version conflict', async () => {
    m.saveInsolvencyEvidence.mockRejectedValueOnce({
      response: { data: { error: { code: 'row_version_conflict' } } },
    })
    const wrapper = mount(PayrollInsolvency)
    await flushPromises()
    m.insolvencyOptions.mockClear()
    m.insolvencyEvidence.mockClear()

    await wrapper.get('[data-test="insolvency-save"]').trigger('click')
    await flushPromises()

    expect(m.insolvencyOptions).toHaveBeenCalledOnce()
    expect(m.insolvencyEvidence).toHaveBeenCalledOnce()
    expect(m.warning).toHaveBeenCalled()
  })
})
