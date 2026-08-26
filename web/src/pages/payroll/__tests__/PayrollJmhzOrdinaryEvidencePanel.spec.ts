import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  confirm: vi.fn(),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzOrdinaryEvidence: m.get,
    confirmJmhzOrdinaryEvidence: m.confirm,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

vi.mock('vue-router', () => ({
  RouterLink: {
    props: ['to'],
    template: '<a :href="to"><slot /></a>',
  },
}))

import PayrollJmhzOrdinaryEvidencePanel from '@/pages/payroll/PayrollJmhzOrdinaryEvidencePanel.vue'

const run = {
  id: 8,
  revision_id: 18,
  revision_no: 2,
  revision_status: 'approved',
  period_start: '2026-08-01',
}

describe('PayrollJmhzOrdinaryEvidencePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.get.mockResolvedValue({
      scopes: [{
        employee_id: 11,
        employment_id: 101,
        employee_name: 'Osoba A',
        confirmed: false,
      }],
      evidences: [],
    })
    m.confirm.mockResolvedValue({
      id: 31,
      revision_id: 18,
      confirmed_at: '2026-08-13T12:00:00Z',
      source_manifest_sha256: 'a'.repeat(64),
    })
  })

  it('u běžných vztahů nevyžaduje měsíční checkboxy ani jednotlivé karty', async () => {
    m.get.mockResolvedValue({
      scopes: Array.from({ length: 1000 }, (_, index) => ({
        employee_id: index + 1,
        employment_id: index + 101,
        employee_name: `Osoba ${index + 1}`,
        confirmed: false,
        resolution: 'automatic_on_preparation',
        attention_code: null,
        attention_message: null,
      })),
      evidences: [],
    })
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-test="jmhz-ordinary-evidence-scope"]')).toHaveLength(0)
    expect(wrapper.text()).toContain('jmhz_evidence_automatic_count')
    expect(m.confirm).not.toHaveBeenCalled()
  })

  /**
   * Regrese: dokud šla evidence potvrdit jen za revizi, firma s víc lidmi
   * neměla kde potvrdit druhého — panel uměl jednu sadu zaškrtávátek na revizi.
   */
  it('ukáže jednotlivě jen vztahy, které skutečně vyžadují pozornost', async () => {
    m.get.mockResolvedValue({
      scopes: [
        {
          employee_id: 11,
          employment_id: 101,
          employee_name: 'Osoba A',
          confirmed: false,
          resolution: 'attention_required',
          attention_code: 'jmhz_ordinary_evidence_monthly_exception_required',
          attention_message: 'Doplňte měsíční údaje výjimky.',
        },
        {
          employee_id: 12,
          employment_id: 102,
          employee_name: 'Osoba B',
          confirmed: true,
          resolution: 'confirmed',
          attention_code: null,
          attention_message: null,
        },
      ],
      evidences: [{
        id: 30,
        employee_id: 12,
        employment_id: 102,
        confirmed_at: '2026-08-13T12:00:00Z',
        source_manifest_sha256: 'b'.repeat(64),
      }],
    })
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.findAll('[data-test="jmhz-ordinary-evidence-scope"]')).toHaveLength(1)
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
    expect(wrapper.get('[data-test="jmhz-ordinary-evidence-pending"]').text())
      .toContain('jmhz_evidence_pending')
    expect(wrapper.text()).toContain('Doplňte měsíční údaje výjimky.')
    expect(wrapper.text()).toContain('jmhz_evidence_attention_employment_action')
    expect(wrapper.text()).not.toContain('jmhz_ordinary_evidence_monthly_exception_required')
    expect(m.confirm).not.toHaveBeenCalled()
  })

  it('vede evidovanou srážku přímo do agendy srážek bez interního kódu', async () => {
    m.get.mockResolvedValue({
      scopes: [{
        employee_id: 11,
        employment_id: 101,
        employee_name: 'Osoba A',
        confirmed: false,
        resolution: 'attention_required',
        attention_code: 'jmhz_ordinary_evidence_deduction_conflict',
        attention_message: 'Revize obsahuje evidovanou srážku ze mzdy.',
      }],
      evidences: [],
    })
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.get('a').attributes('href')).toBe('/payroll/enforcement?person=11')
    expect(wrapper.text()).toContain('jmhz_evidence_attention_deductions_action')
    expect(wrapper.text()).not.toContain('jmhz_ordinary_evidence_deduction_conflict')
  })

  it('vede starou revizi k novému přepočtu místo na neexistující detail vztahu', async () => {
    m.get.mockResolvedValue({
      scopes: [{
        employee_id: 11,
        employment_id: 101,
        employee_name: 'Osoba A',
        confirmed: false,
        resolution: 'attention_required',
        attention_code: 'jmhz_ordinary_evidence_profile_missing',
        attention_message: 'Tato revize vznikla před doplněním podkladů JMHZ. Mzdu znovu přepočítejte a schvalte.',
      }],
      evidences: [],
    })
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.get('a').attributes('href')).toBe('/payroll/runs')
    expect(wrapper.text()).toContain('jmhz_evidence_attention_run_action')
    expect(wrapper.text()).not.toContain('jmhz_ordinary_evidence_profile_missing')
  })

  it('v režimu jen pro čtení nepovolí potvrzení', async () => {
    m.canWrite.mockReturnValue(false)
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })
})
