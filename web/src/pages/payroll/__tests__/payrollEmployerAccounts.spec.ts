import { describe, expect, it } from 'vitest'
import type { PayrollAccountOption } from '@/api/payroll'
import {
  PAYROLL_ACCOUNT_TYPES,
  payrollAccountError,
  payrollAccountOptions,
} from '@/pages/payroll/payrollEmployerAccounts'

function account(
  account_code: string,
  account_type: PayrollAccountOption['account_type'],
  is_active = true,
): PayrollAccountOption {
  return {
    id: Number(account_code.replace(/\D/g, '')) || 1,
    account_code,
    name: `Účet ${account_code}`,
    account_type,
    is_synthetic: account_code.length === 3,
    parent_id: null,
    is_active,
  }
}

describe('payrollEmployerAccounts', () => {
  const accounts = [
    account('521', 'expense'),
    account('521001', 'expense'),
    account('331', 'liability'),
    account('379', 'liability', false),
  ]

  it('nabízí jen aktivní účty backendového typu daného slotu', () => {
    expect(payrollAccountOptions(accounts, 'employment_gross_debit').map(option => option.value))
      .toEqual(['521', '521001'])
    expect(payrollAccountOptions(accounts, 'employment_gross_credit').map(option => option.value))
      .toEqual(['331'])
  })

  it('odliší neexistující, neaktivní a typově chybný účet', () => {
    expect(payrollAccountError(accounts, 'employment_gross_debit', '999')).toBe('not_found')
    expect(payrollAccountError(accounts, 'other_deductions_credit', '379')).toBe('inactive')
    expect(payrollAccountError(accounts, 'employment_gross_debit', '331')).toBe('wrong_type')
    expect(payrollAccountError(accounts, 'employment_gross_debit', '52-1')).toBe('invalid_format')
    expect(payrollAccountError(accounts, 'employment_gross_debit', ' 521 ')).toBeNull()
  })

  /*
   * Migrace 1618 přepnula výchozí pojistné na analytiky 336.100 / 336.200.
   * Kratší limit {0,7} je propouštěl, ale ne každý delší kód — a hlavně se
   * rozcházel s PayrollAccountCode::isValid() a s validátorem nastavení mezd,
   * takže obrazovka odmítala kód, který backend bere.
   */
  it('bere analytiku až do 13 znaků za syntetikou, stejně jako backend', () => {
    const analytics = [
      account('336.100', 'liability'),
      account('336.200123456789', 'liability'),
    ]
    expect(payrollAccountError(analytics, 'social_insurance_credit', '336.100')).toBeNull()
    expect(payrollAccountError(analytics, 'health_insurance_credit', '336.200123456789'))
      .toBeNull()
    // O znak přes limit backendu — musí spadnout na formát, ne na „účet nenalezen".
    expect(payrollAccountError(analytics, 'health_insurance_credit', '336.2001234567891'))
      .toBe('invalid_format')
  })

  /*
   * Backend zná 17 předkontací (PayrollAccountingDefaults::ACCOUNTS). Klíč,
   * který v mapě typů chybí, se v nastavení mezd vůbec nevykreslí — účetní si
   * ho tedy nenastaví, přestože se podle něj účtuje.
   */
  it('zná všech pět předkontací doplněných migracemi 1614 a 1618', () => {
    expect(PAYROLL_ACCOUNT_TYPES.risky_savings_debit).toBe('expense')
    expect(PAYROLL_ACCOUNT_TYPES.risky_savings_credit).toBe('liability')
    expect(PAYROLL_ACCOUNT_TYPES.non_deductible_benefit_debit).toBe('expense')
    expect(PAYROLL_ACCOUNT_TYPES.travel_expense_debit).toBe('expense')
    // Přeplatek čisté mzdy je pohledávka za zaměstnancem, ne závazek.
    expect(PAYROLL_ACCOUNT_TYPES.employee_receivable_debit).toBe('asset')
  })

  it('nabízí pohledávce jen aktivní účty typu asset', () => {
    const withAssets = [
      ...accounts,
      account('335', 'asset'),
      account('335900', 'asset', false),
      account('527', 'expense'),
    ]
    expect(payrollAccountOptions(withAssets, 'employee_receivable_debit')
      .map(option => option.value)).toEqual(['335'])
    expect(payrollAccountError(withAssets, 'employee_receivable_debit', '527')).toBe('wrong_type')
    expect(payrollAccountError(withAssets, 'risky_savings_debit', '527')).toBeNull()
  })
})
