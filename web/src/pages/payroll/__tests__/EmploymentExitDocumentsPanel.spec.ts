import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollEmployment } from '@/api/payroll'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

const m = vi.hoisted(() => ({
  employmentExitDocuments: vi.fn(),
  generateEmploymentCertificate: vi.fn(),
  generateAverageEarningsCertificate: vi.fn(),
  generateAverageEarningsStatement: vi.fn(),
  downloadDocument: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employmentExitDocuments: m.employmentExitDocuments,
    generateEmploymentCertificate: m.generateEmploymentCertificate,
    generateAverageEarningsCertificate: m.generateAverageEarningsCertificate,
    generateAverageEarningsStatement: m.generateAverageEarningsStatement,
    downloadDocument: m.downloadDocument,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    te: (key: string) => key.includes('average_earnings_snapshot_missing'),
  }),
}))

import EmploymentExitDocumentsPanel from '@/pages/payroll/EmploymentExitDocumentsPanel.vue'

function employment(relationType: PayrollEmployment['relation_type'] = 'employment'): PayrollEmployment {
  return {
    id: 12,
    employee_id: 20,
    office_id: null,
    office_code: null,
    office_name: null,
    code: 'TEST-12',
    relation_type: relationType,
    meal_entitlement_basis: 'shift',
    status: 'ended',
    is_primary: true,
    start_date: '2026-01-01',
    actual_start_date: '2026-01-01',
    end_date: '2026-07-31',
    archived_at: null,
    is_legacy_projection: false,
    monthly_gross_minor: 4000000,
    row_version: 2,
    allowed_transitions: ['archived'],
    can_delete: false,
    delete_blocker: null,
    delete_cascade: {},
    accounting: {
      gross_debit: '521',
      gross_credit: '331',
      employer_insurance_debit: '524',
      employer_insurance_credit: '336',
    },
    terms: [],
    checklist: [],
    timeline: [],
  }
}

function readiness(overrides: Record<string, unknown> = {}) {
  return {
    employment_id: 12,
    readiness: {
      employment_certificate: {
        available: true,
        readiness_code: null,
        deduction_claim_ids: [91],
      },
      average_earnings_certificate: {
        available: false,
        readiness_code: 'average_earnings_snapshot_missing',
        decisive_year: 2026,
        decisive_quarter: 3,
      },
      average_earnings_statement: {
        available: false,
        readiness_code: 'average_earnings_snapshot_missing',
        decisive_year: 2026,
        decisive_quarter: 3,
      },
      ...overrides,
    },
    items: [],
  }
}

function available() {
  return {
    average_earnings_certificate: {
      available: true,
      readiness_code: null,
      decisive_year: 2026,
      decisive_quarter: 3,
    },
    average_earnings_statement: {
      available: true,
      readiness_code: null,
      decisive_year: 2026,
      decisive_quarter: 3,
    },
  }
}

describe('EmploymentExitDocumentsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.employmentExitDocuments.mockResolvedValue(readiness())
    m.generateEmploymentCertificate.mockResolvedValue({ id: 88 })
    m.generateAverageEarningsCertificate.mockResolvedValue({ id: 89 })
    m.generateAverageEarningsStatement.mockResolvedValue({ id: 90 })
    m.downloadDocument.mockResolvedValue(undefined)
  })

  it('shows §313(2) as unavailable and never exposes an amount input', async () => {
    const wrapper = mount(EmploymentExitDocumentsPanel, {
      props: { employment: employment(), canWrite: true },
    })
    await flushPromises()

    await wrapper.get('[data-test="exit-tab-average"]').trigger('click')

    expect(wrapper.get('[data-test="average-certificate-unavailable"]').text())
      .toContain('average_earnings_snapshot_missing')
    expect(wrapper.find('input[type="number"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('payroll.people.exit_documents.generate')
  })

  it('submits complete legal evidence for the exact closed-ledger claim', async () => {
    const wrapper = mount(EmploymentExitDocumentsPanel, {
      props: { employment: employment(), canWrite: true },
    })
    await flushPromises()

    await wrapper.get('[data-test="open-employment-certificate-form"]').trigger('click')
    const textareas = wrapper.findAll('textarea')
    await textareas[0].setValue('Synthetic work')
    await textareas[1].setValue('Synthetic qualification')
    await textareas[2].setValue('Synthetic exposure fact')
    const deductionInputs = wrapper.findAll('[data-test="employment-certificate-form"] input:not([type="checkbox"]):not([type="date"])')
    await deductionInputs[0].setValue('Synthetic beneficiary')
    await deductionInputs[1].setValue('Synthetic authority')
    await deductionInputs[2].setValue('TEST-91')
    for (const checkbox of wrapper.findAll('input[type="checkbox"]')) {
      await checkbox.setValue(true)
    }
    await wrapper.get('[data-test="employment-certificate-form"]').trigger('submit')
    await flushPromises()

    expect(m.generateEmploymentCertificate).toHaveBeenCalledWith(
      12,
      expect.objectContaining({
        work_description: 'Synthetic work',
        deductions: [{
          source_claim_id: 91,
          beneficiary: 'Synthetic beneficiary',
          ordering_authority: 'Synthetic authority',
          decision_reference: 'TEST-91',
        }],
        dpp_issuance_basis: null,
        correction_reason: null,
      }),
      expect.stringContaining('employment-exit-12-'),
    )
  })

  it('never lets the client type the net amount for the §313(2) certificate', async () => {
    m.employmentExitDocuments.mockResolvedValue(readiness(available()))
    const wrapper = mount(EmploymentExitDocumentsPanel, {
      props: { employment: employment(), canWrite: true },
    })
    await flushPromises()

    await wrapper.get('[data-test="exit-tab-average"]').trigger('click')
    await wrapper.get('[data-test="open-average-certificate-form"]').trigger('click')
    for (const checkbox of wrapper.findAll('[data-test="average-certificate-form"] input[type="checkbox"]')) {
      await checkbox.setValue(true)
    }
    await wrapper.get('[data-test="average-certificate-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.find('input[type="number"]').exists()).toBe(false)
    expect(m.generateAverageEarningsCertificate).toHaveBeenCalledWith(
      12,
      {
        termination_assessment_complete: true,
        termination_reason_kind: 'none',
        employee_stated_reason: null,
        pension_insurance_periods: [],
        correction_reason: null,
      },
      expect.stringContaining('employment-exit-12-'),
    )
  })

  it('issues the standalone average earnings statement with its stated purpose', async () => {
    m.employmentExitDocuments.mockResolvedValue(readiness(available()))
    const wrapper = mount(EmploymentExitDocumentsPanel, {
      props: { employment: employment(), canWrite: true },
    })
    await flushPromises()

    await wrapper.get('[data-test="exit-tab-statement"]').trigger('click')
    await wrapper.get('[data-test="open-average-statement-form"]').trigger('click')
    await wrapper.get('[data-test="average-statement-form"] input').setValue('Hypoteční úvěr')
    await wrapper.get('[data-test="average-statement-form"]').trigger('submit')
    await flushPromises()

    expect(m.generateAverageEarningsStatement).toHaveBeenCalledWith(
      12,
      { requested_purpose: 'Hypoteční úvěr', correction_reason: null },
      expect.stringContaining('employment-exit-12-'),
    )
  })

  it('uses the shared payroll select and a filled primary add action', async () => {
    const wrapper = mount(EmploymentExitDocumentsPanel, {
      props: { employment: employment(), canWrite: true },
    })
    await flushPromises()
    await wrapper.get('[data-test="open-employment-certificate-form"]').trigger('click')
    await wrapper.get('[data-test="add-pension-period"]').trigger('click')

    expect(wrapper.findAllComponents(SearchableSelect)).toHaveLength(1)
    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.get('[role="combobox"]').classes())
      .toContain('focus:border-payroll-500')
    expect(wrapper.find('[aria-label="Zrušit výběr"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="add-pension-period"]').classes())
      .toContain('bg-primary-600')
  })
})
