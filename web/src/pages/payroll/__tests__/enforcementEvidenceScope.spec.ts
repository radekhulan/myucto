import { describe, expect, it } from 'vitest'
import type {
  EnforcementCaseSummary,
  EnforcementDependant,
  EnforcementMonthEvidence,
} from '@/api/payrollEnforcement'
import {
  eligibleAllowances,
  evidenceScope,
  protectedAmountIsUnattested,
  spousePensionEvidenceUnknown,
  type EvidenceScopeInput,
} from '@/pages/payroll/enforcementEvidenceScope'

/*
 * Obrazovka o rozsahu nerozhoduje, jen zrcadlí
 * `GarnishmentCalculator::evidenceScope()`. Testy jsou proto psané jako
 * kontrakt proti té metodě: každý případ odpovídá jedné větvi PHP kódu.
 */

const PERIOD = '2026-06'

function summary(overrides: Partial<EnforcementCaseSummary> = {}): EnforcementCaseSummary {
  return {
    id: 11,
    employee_id: 3,
    full_name: 'Syntetický Povinný',
    case_kind: 'enforcement',
    status: 'withhold_and_hold',
    effective_from: '2020-01-01',
    effective_to: null,
    evidence_complete: false,
    recipient_verified: false,
    row_version: 1,
    claim_count: 1,
    outstanding_minor_units: 250_000,
    created_at: '2020-01-01 08:00:00',
    updated_at: '2020-01-01 08:00:00',
    ...overrides,
  }
}

function dependant(overrides: Partial<EnforcementDependant> = {}): EnforcementDependant {
  return {
    id: 1,
    employee_id: 3,
    dependant_kind: 'dependant',
    valid_from: '2020-01-01',
    valid_to: null,
    eligibility_verified: true,
    excluded_for_maintenance: false,
    // Doložení důchodu podle nař. vlády č. 441/2024 Sb. U dítěte je bez významu;
    // testy manžela si ho přebijí přes overrides.
    quarter_pension_evidence: 'not_documented',
    quarter_pension_holder: null,
    quarter_pension_kind: null,
    quarter_pension_documented_on: null,
    row_version: 1,
    ...overrides,
  }
}

function evidence(
  overrides: Partial<EnforcementMonthEvidence> = {},
): EnforcementMonthEvidence {
  return {
    id: 5,
    employee_id: 3,
    period_start: `${PERIOD}-01`,
    claim_register_evidence_complete: false,
    dependants_evidence_complete: false,
    spouse_evidence_complete: false,
    pension_evidence: 'unknown',
    has_multiple_payers: false,
    protected_amount_override_minor_units: null,
    protected_amount_override_verified: false,
    insolvency_mode: 'none',
    insolvency_decision_verified: false,
    insolvency_recipient_verified: false,
    insolvency_payment_instruction_id: null,
    insolvency_employment_id: null,
    insolvency_institution_account_id: null,
    insolvency_decision_document_id: null,
    insolvency_payment_instruction_hash: null,
    court_determined_amount_minor_units: null,
    row_version: 1,
    ...overrides,
  }
}

function input(overrides: Partial<EvidenceScopeInput> = {}): EvidenceScopeInput {
  return {
    period: PERIOD,
    cases: [],
    casesComplete: true,
    dependants: [],
    evidence: evidence(),
    ...overrides,
  }
}

describe('enforcementEvidenceScope', () => {
  /*
   * Firma o tisíci lidech měla ročně 12 000 zápisů, které u člověka bez jediné
   * exekuce nedokládaly nic. Tohle je ten případ.
   */
  it('marks every evidence as not applicable for a person without a case', () => {
    expect(evidenceScope(input())).toEqual({
      claim_register: 'not_applicable',
      dependants: 'not_applicable',
      spouse: 'not_applicable',
    })
  })

  it('asks for the claim register once an active case can take money', () => {
    const scope = evidenceScope(input({ cases: [summary()] }))

    expect(scope.claim_register).toBe('missing')
  })

  /*
   * Případ, který nesráží (zastavený, zaplacený, jen přijatý), do výpočtu
   * nevstupuje — backend ho vyfiltruje stavem, obrazovka musí taky.
   */
  it.each(['received', 'deferred_no_withholding', 'paid', 'stopped'] as const)(
    'ignores a case in status %s',
    (status) => {
      const scope = evidenceScope(input({ cases: [summary({ status })] }))

      expect(scope.claim_register).toBe('not_applicable')
    },
  )

  it('ignores a case whose validity does not reach the period', () => {
    const scope = evidenceScope(input({
      cases: [summary({ effective_from: '2020-01-01', effective_to: '2026-05-31' })],
    }))

    expect(scope.claim_register).toBe('not_applicable')
  })

  it('ignores a case with nothing left outstanding', () => {
    const scope = evidenceScope(input({
      cases: [summary({ outstanding_minor_units: 0 })],
    }))

    expect(scope.claim_register).toBe('not_applicable')
  })

  /*
   * Insolvence je v podmínce záměrně: souběžná exekuce je v tom režimu důvod
   * k ručnímu posouzení, takže vědět o ní je věcné i bez jediné pohledávky.
   */
  it('keeps the claim register in scope during insolvency without any case', () => {
    const scope = evidenceScope(input({
      evidence: evidence({ insolvency_mode: 'approved_standard' }),
    }))

    expect(scope.claim_register).toBe('missing')
  })

  it('marks a declared register as declared regardless of the scope', () => {
    const scope = evidenceScope(input({
      evidence: evidence({ claim_register_evidence_complete: true }),
    }))

    expect(scope.claim_register).toBe('declared')
  })

  /*
   * Nároky se řídí JINÝM pravidlem než rejstřík: nezabavitelná částka se počítá
   * i v měsíci bez exekuce, protože z ní § 148 odst. 2 zákoníku práce odvozuje
   * strop dobrovolné dohody o srážkách. Nedoložený nárok proto nezmizí ze
   * scény, jen přestane blokovat běh.
   */
  it('reports an undocumented allowance as nothing_withheld when nothing is withheld', () => {
    const scope = evidenceScope(input({
      dependants: [dependant(), dependant({ id: 2, dependant_kind: 'spouse_partner' })],
    }))

    expect(scope).toEqual({
      claim_register: 'not_applicable',
      dependants: 'nothing_withheld',
      spouse: 'nothing_withheld',
    })
    expect(protectedAmountIsUnattested(scope)).toBe(true)
  })

  it('turns the same undocumented allowance into a blocker once a case withholds', () => {
    const scope = evidenceScope(input({
      cases: [summary()],
      dependants: [dependant(), dependant({ id: 2, dependant_kind: 'spouse_partner' })],
    }))

    expect(scope.dependants).toBe('missing')
    expect(scope.spouse).toBe('missing')
    expect(protectedAmountIsUnattested(scope)).toBe(false)
  })

  // Při souběhu plátců určuje nezabavitelnou částku soud, ne naše evidence.
  it('drops both allowances out of scope when multiple payers pay the income', () => {
    const scope = evidenceScope(input({
      cases: [summary()],
      dependants: [dependant(), dependant({ id: 2, dependant_kind: 'spouse_partner' })],
      evidence: evidence({ has_multiple_payers: true }),
    }))

    expect(scope.dependants).toBe('not_applicable')
    expect(scope.spouse).toBe('not_applicable')
    expect(protectedAmountIsUnattested(scope)).toBe(false)
  })

  it('counts only verified allowances that are not excluded for maintenance', () => {
    const allowances = eligibleAllowances([
      dependant(),
      dependant({ id: 2, eligibility_verified: false }),
      dependant({ id: 3, excluded_for_maintenance: true }),
      dependant({ id: 4, valid_to: '2026-05-31' }),
      dependant({ id: 5, dependant_kind: 'spouse_partner', eligibility_verified: false }),
    ], PERIOD)

    expect(allowances).toEqual({ dependants: 1, spouse: false })
  })

  /*
   * Neúplný seznam případů nesmí nic zešednout — obrazovka by tvrdila, že není
   * co dokládat, a přitom by o polovině případů nevěděla.
   */
  it('keeps everything in scope when the case list is incomplete', () => {
    const scope = evidenceScope(input({
      casesComplete: false,
      dependants: [dependant()],
    }))

    expect(scope.claim_register).toBe('missing')
    expect(scope.dependants).toBe('missing')
  })

  /*
   * Nař. vlády č. 441/2024 Sb. — čtvrtina na manžela od 1. 1. 2025 náleží jen
   * při doloženém důchodu. Backend proto do rozsahu bere `spouse_evidence_complete`
   * AŽ SPOLU s doložením; kdyby obrazovka pořád hlásila `declared`, tvrdila by,
   * že je hotovo, a účetní by se o chybějícím podkladu dozvěděla až z běhu mezd.
   */
  const spouse = (overrides: Partial<EnforcementDependant> = {}) => dependant({
    id: 9,
    dependant_kind: 'spouse_partner',
    ...overrides,
  })

  it('keeps the spouse evidence in scope while the pension evidence is unknown', () => {
    const scope = evidenceScope(input({
      cases: [summary()],
      dependants: [spouse({ quarter_pension_evidence: 'unknown' })],
      evidence: evidence({ spouse_evidence_complete: true }),
    }))

    expect(scope.spouse).toBe('missing')
  })

  /*
   * `not_documented` NENÍ mezera v evidenci: povinný důkazní břemeno neunesl,
   * čtvrtina nenáleží a dokládat není co. Kdyby i tenhle stav držel rozsah
   * otevřený, obrazovka by pobízela k doložení, které nikdy nepřijde.
   */
  it.each(['documented', 'not_documented'] as const)(
    'treats a spouse with %s pension evidence as declared',
    (quarter_pension_evidence) => {
      const scope = evidenceScope(input({
        cases: [summary()],
        dependants: [spouse({ quarter_pension_evidence })],
        evidence: evidence({ spouse_evidence_complete: true }),
      }))

      expect(scope.spouse).toBe('declared')
    },
  )

  /*
   * Zrcadlí `weakerSpousePension()`: rozhoduje NEJSLABŠÍ stav napříč uplatněnými
   * záznamy, takže jediné `unknown` stačí i vedle doloženého manžela.
   */
  it('lets a single unknown record outweigh a documented one', () => {
    expect(spousePensionEvidenceUnknown([
      spouse({ quarter_pension_evidence: 'documented' }),
      spouse({ id: 10, quarter_pension_evidence: 'unknown' }),
    ], PERIOD)).toBe(true)
  })

  /*
   * Záznam, který se v měsíci neuplatní, blokovat nesmí — jinak by účetní
   * doplňovala důchod k manželovi, který do nezabavitelné částky nevstupuje.
   */
  it('ignores unknown evidence on records that do not claim the allowance', () => {
    expect(spousePensionEvidenceUnknown([
      spouse({ quarter_pension_evidence: 'unknown', eligibility_verified: false }),
      spouse({ id: 10, quarter_pension_evidence: 'unknown', excluded_for_maintenance: true }),
      spouse({ id: 11, quarter_pension_evidence: 'unknown', valid_to: '2026-05-31' }),
      // Dítě podmínku nemá vůbec, i kdyby v řádku `unknown` zůstalo.
      dependant({ id: 12, quarter_pension_evidence: 'unknown' }),
    ], PERIOD)).toBe(false)
  })
})
