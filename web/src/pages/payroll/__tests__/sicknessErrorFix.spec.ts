import { describe, expect, it } from 'vitest'
import { sicknessErrorFix, sicknessErrorProblems } from '../sicknessErrorFix'

const item = { employee_id: 41, employment_id: 77 }

function axiosError(error: Record<string, unknown>) {
  return { isAxiosError: true, response: { data: { error } } }
}

describe('sicknessErrorFix', () => {
  it('chybějící měsíc, který počítá MyÚčto, vede do mzdových běhů za to období, ne do kontroly převodu', () => {
    const problems = sicknessErrorProblems(axiosError({
      code: 'nempri_decisive_month_missing',
      message: 'Mzdu za 2026-01 počítá MyÚčto…',
      problems: [{ code: 'nempri_decisive_month_missing', payroll_periods: ['2026-01'], takeover_periods: [] }],
    }))

    expect(sicknessErrorFix(problems, item)).toEqual({
      links: [{ kind: 'runs', to: { name: 'payroll-runs', query: { period: '2026-01' } } }],
      edit: true,
    })
  })

  it('převzatý měsíc vede do kontroly převodu, odpověď bez rozpadu také', () => {
    const takeover = sicknessErrorProblems(axiosError({
      code: 'nempri_decisive_month_missing',
      problems: [{ code: 'nempri_decisive_month_missing', payroll_periods: [], takeover_periods: ['2025-11'] }],
    }))
    const legacy = sicknessErrorProblems(axiosError({ code: 'nempri_decisive_month_missing', message: 'x' }))
    const reconciliation = { kind: 'reconciliation', to: { path: '/payroll/imports', query: { tab: 'reconciliation' } } }

    expect(sicknessErrorFix(takeover, item)?.links).toEqual([reconciliation])
    expect(sicknessErrorFix(legacy, item)?.links).toEqual([reconciliation])
  })

  it('každá z více chyb náhledu dostane svůj proklik na správnou sekci', () => {
    const problems = sicknessErrorProblems(axiosError({
      code: 'nempri_decisive_month_missing',
      problems: [
        { code: 'nempri_decisive_month_missing', payroll_periods: ['2026-01'], takeover_periods: [] },
        { code: 'nempri_payment_connection_missing' },
        { code: 'nempri_activity_code_missing' },
        { code: 'nempri_decision_number_missing' },
      ],
    }))

    const fix = sicknessErrorFix(problems, item)

    expect(fix?.edit).toBe(true)
    expect(fix?.links).toEqual([
      { kind: 'runs', to: { name: 'payroll-runs', query: { period: '2026-01' } } },
      // Výplatní účet je na záložce Výplata karty osoby (sekce `accounts`).
      { kind: 'person', to: { name: 'payroll-people', query: { person: '41', panel: 'accounts' } } },
      // Druh činnosti je v podmínkách vztahu, ne v případu dávky.
      {
        kind: 'employment_terms',
        to: { name: 'payroll-people', query: { person: '41', employment: '77', panel: 'employment_terms', field: 'activity_code' } },
      },
    ])
  })

  it('identifikátory zaměstnavatele vedou do nastavení mezd', () => {
    expect(sicknessErrorFix([{ code: 'sickness_variable_symbol_missing' }], item)).toEqual({
      links: [{ kind: 'settings', to: { name: 'payroll-settings', query: { tab: 'employer' }, hash: '#payroll-employer-offices' } }],
      edit: false,
    })
    expect(sicknessErrorFix([{ code: 'sickness_ossz_code_missing' }], item)?.links[0]?.to)
      .toEqual({ name: 'payroll-settings', query: { tab: 'employer' } })
  })

  it('rodné číslo a identita vedou na své sekce karty osoby', () => {
    expect(sicknessErrorFix([{ code: 'nempri_birth_number_missing' }], item)?.links[0]?.to)
      .toEqual({ name: 'payroll-people', query: { person: '41', panel: 'identifiers' } })
    expect(sicknessErrorFix([{ code: 'sickness_identity_incomplete' }], item)?.links[0]?.to)
      .toEqual({ name: 'payroll-people', query: { person: '41', panel: 'registration_identity' } })
  })

  it('neznámá chyba bez místa nápravy proklik nenabízí', () => {
    expect(sicknessErrorFix([{ code: 'sickness_case_conflict' }], item)).toBeNull()
    expect(sicknessErrorProblems({ message: 'Network Error' })).toEqual([])
  })
})
