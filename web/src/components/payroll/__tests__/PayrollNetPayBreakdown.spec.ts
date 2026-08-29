import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollRunResultPerson } from '@/api/payroll'
import type { NetResultBreakdown } from '@/api/payrollDeductions'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: ref('cs-CZ'),
    t: (key: string) => key,
  }),
}))

const netResultMock = vi.fn()
vi.mock('@/api/payrollDeductions', () => ({
  payrollDeductionsApi: { netResult: (...args: unknown[]) => netResultMock(...args) },
}))

import PayrollNetPayBreakdown from '@/components/payroll/PayrollNetPayBreakdown.vue'

function people(): PayrollRunResultPerson[] {
  return [
    { employee_id: 31, statutory: { person_reference: 'employee:31', status: 'calculated' } },
  ]
}

/**
 * Fixture je doslovný tvar odpovědi backendu a čísla musí sedět mezi sebou:
 * 30 000 hrubého − 2 130 sociální − 1 350 zdravotní − 2 100 záloha na daň
 * = 24 420 čistého před srážkami. Dohoda si řekla o 2 000, srazilo se 0,
 * takže 2 000 zůstává neuplatněných a k výplatě jde celých 24 420.
 */
function fixture(overrides: Partial<NetResultBreakdown> = {}): NetResultBreakdown {
  return {
    revision: { id: 9, run_id: 4, revision_no: 1, revision_kind: 'regular', status: 'approved' },
    person: { employee_id: 31, full_name: 'Syntetická osoba' },
    income: {
      cash_minor: 3_000_000,
      non_cash_minor: 0,
      gross_minor: 3_000_000,
      relationships: [],
    },
    contributions: { employee_social_minor: 213_000, employee_health_minor: 135_000 },
    tax: { advance_minor: 210_000, withholding_minor: 0, bonus_minor: 0 },
    correction_minor: 0,
    net_before_deductions_minor: 2_442_000,
    deductions: [
      {
        agreement_id: 7,
        deduction_reference: 'agreement:7',
        agreement_reference: 'agreement:7',
        title: 'Spoření',
        deduction_kind: 'other',
        total_limit_minor: null,
        priority_no: 1,
        requested_minor: 200_000,
        applied_minor: 0,
        active: true,
        unapplied_minor: 200_000,
        accounting_unapplied_minor: 200_000,
      },
    ],
    deducted_minor: 0,
    net_payable_minor: 2_442_000,
    enforcement_withheld_minor: 0,
    enforcement_evidence_source: {
      claim_register: 'not_applicable',
      dependants: 'nothing_withheld',
      spouse: 'not_applicable',
    },
    payable_after_enforcement_minor: 2_442_000,
    allocation_status: 'no_rules',
    allocations: [],
    allocations_total_minor: 0,
    ...overrides,
  }
}

function mountBreakdown() {
  return mount(PayrollNetPayBreakdown, {
    props: { revisionId: 9, approved: true, people: people() },
  })
}

describe('PayrollNetPayBreakdown', () => {
  /*
   * „Nevešlo se to do nezabavitelné částky" a „nezabavitelná částka není
   * doložená, takže se nesráží nic" vypadají v částkách stejně, ale řeší se
   * opačně — jedno penězi, druhé doložením nároku. Bez věty by uživatel neměl
   * jak poznat, kterou z těch dvou věcí má udělat.
   */
  it('explains an unapplied deduction that an undocumented allowance closed off', async () => {
    netResultMock.mockResolvedValue(fixture())

    const wrapper = mountBreakdown()
    await flushPromises()

    const reason = wrapper.find('[data-test="unapplied-reason"]')
    expect(reason.exists()).toBe(true)
    expect(reason.text()).toBe('payroll.runs.net.unapplied_unattested')
    wrapper.unmount()
  })

  // Běžný nedostatek kapacity žádný důvod nedostane — obecná věta by jen
  // zopakovala, co je z nesražené částky vidět.
  it('stays quiet when the deduction simply did not fit', async () => {
    netResultMock.mockResolvedValue(fixture({
      enforcement_evidence_source: {
        claim_register: 'declared',
        dependants: 'declared',
        spouse: 'not_applicable',
      },
    }))

    const wrapper = mountBreakdown()
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.runs.net.unapplied')
    expect(wrapper.find('[data-test="unapplied-reason"]').exists()).toBe(false)
    wrapper.unmount()
  })

  /*
   * Revize spočtená dřív, než se rozsah začal ukládat, o něm netvrdí nic —
   * dopočítat se nesmí, takže obrazovka mlčí místo aby si důvod domyslela.
   */
  it('stays quiet for a revision that predates the stored scope', async () => {
    netResultMock.mockResolvedValue(fixture({ enforcement_evidence_source: null }))

    const wrapper = mountBreakdown()
    await flushPromises()

    expect(wrapper.find('[data-test="unapplied-reason"]').exists()).toBe(false)
    wrapper.unmount()
  })

  // Důvod patří k nesražené částce, ne k řádku, který se srazil celý.
  it('does not attach the reason to a deduction that was applied in full', async () => {
    netResultMock.mockResolvedValue(fixture({
      deductions: [{
        agreement_id: 7,
        deduction_reference: 'agreement:7',
        agreement_reference: 'agreement:7',
        title: 'Spoření',
        deduction_kind: 'other',
        total_limit_minor: null,
        priority_no: 1,
        requested_minor: 200_000,
        applied_minor: 200_000,
        active: true,
        unapplied_minor: 0,
        accounting_unapplied_minor: 0,
      }],
      deducted_minor: 200_000,
    }))

    const wrapper = mountBreakdown()
    await flushPromises()

    expect(wrapper.find('[data-test="unapplied-reason"]').exists()).toBe(false)
    wrapper.unmount()
  })

  /*
   * Nález E-17. Pozastavená dohoda měla `unapplied = requested`, protože to je
   * účetní zbytek ze zmrazeného snímku — obrazovka z toho udělala „neuplatněno
   * 2 000 Kč", jako by se nepodařilo srazit. Nesrazilo se ale proto, že se
   * srážet NEMĚLO. Backend proto posílá schodek (0) a účetní zbytek zvlášť
   * a obrazovka místo částky vysvětlí, že dohoda v tom měsíci stála.
   */
  it('says a suspended deduction was not run instead of reporting a shortfall', async () => {
    netResultMock.mockResolvedValue(fixture({
      deductions: [{
        agreement_id: 7,
        deduction_reference: 'agreement:7',
        agreement_reference: 'agreement:7',
        title: 'Spoření',
        deduction_kind: 'other',
        total_limit_minor: null,
        priority_no: 1,
        requested_minor: 200_000,
        applied_minor: 0,
        active: false,
        unapplied_minor: 0,
        accounting_unapplied_minor: 200_000,
      }],
    }))

    const wrapper = mountBreakdown()
    await flushPromises()

    expect(wrapper.find('[data-test="deduction-suspended"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('payroll.runs.net.unapplied')
    expect(wrapper.find('[data-test="unapplied-reason"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
