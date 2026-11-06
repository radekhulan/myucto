import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { cs, en, i18nMessages } from '../../../../tests/locales'
import type { PayrollJmhzXmlDryRunBlocker } from '@/api/payroll'
import { jmhzBlockerLabel, jmhzErrorMessage, jmhzRemediationKind, jmhzRemediationTarget } from '../jmhzBlockerRemediation'
import { payrollCodeKey } from '../payrollServerMessage'

function blocker(overrides: Partial<PayrollJmhzXmlDryRunBlocker>): PayrollJmhzXmlDryRunBlocker {
  return {
    code: 'jmhz_average_hourly_earning_missing',
    entity_type: 'employment',
    entity_id: 7,
    ...overrides,
  } as PayrollJmhzXmlDryRunBlocker
}

function i18n(locale: 'cs' | 'en') {
  const instance = createI18n({ legacy: false, locale, fallbackLocale: 'cs', messages: i18nMessages })
  const t = instance.global.t as unknown as (key: string) => string
  const te = (key: string) => instance.global.te(key)
  return { t, te }
}

describe('navigace nálezů měsíčního hlášení', () => {
  it('druh nápravy bere ze serveru a teprve bez něj odhaduje podle entity', () => {
    expect(jmhzRemediationKind(blocker({ remediation: { kind: 'averages', field: null } }))).toBe('averages')
    expect(jmhzRemediationKind(blocker({ entity_type: 'person' }))).toBe('employee_identity')
    expect(jmhzRemediationKind(blocker({ entity_type: 'office' }))).toBe('office')
    expect(jmhzRemediationKind(blocker({ entity_type: '' }))).toBe('retry')
  })

  it('proklik míří na obrazovku, kde se údaj doplňuje, i s polem', () => {
    const context = { period: '2026-08', employments: [{ id: 7, employee_id: 3 }] }
    expect(jmhzRemediationTarget(
      blocker({ code: 'jmhz_activity_code', remediation: { kind: 'employment_terms', field: 'activity_code' } }),
      7,
      context,
    )).toEqual({
      name: 'payroll-people',
      query: { person: '3', employment: '7', panel: 'employment_terms', field: 'activity_code' },
    })
    expect(jmhzRemediationTarget(
      blocker({ code: 'jmhz_december_ozp_annual_source_missing', remediation: { kind: 'employer_annual', field: null }, entity_type: 'office' }),
      null,
      context,
    )).toEqual({ name: 'payroll-settings', query: { tab: 'submissions' }, hash: '#jmhz-employer-annual-evidence' })
    expect(jmhzRemediationTarget(
      blocker({ code: 'jmhz_deferred_annual_settlement_income_present', remediation: { kind: 'deferred_income', field: null } }),
      7,
      context,
    )).toEqual({ name: 'payroll-people', query: { person: '3', employment: '7', panel: 'deferred_income' } })
    expect(jmhzRemediationTarget(
      blocker({ code: 'jmhz_xml_form_limit_exceeded', remediation: { kind: 'manual', field: null } }),
      7,
      context,
    )).toBeNull()
  })

  it('popisek je z katalogu kódů v jazyce uživatele, ne česká věta serveru', () => {
    const { t, te } = i18n('en')
    const label = jmhzBlockerLabel(t, te, blocker({ code: 'jmhz_deferral_still_blocked', reason: 'Česká věta serveru.' }))
    expect(label).toBe(en.payroll.jmhz_gate.codes.jmhz_deferral_still_blocked)
    expect(label).not.toBe('Česká věta serveru.')
  })

  it('neznámý kód spadne na větu serveru a bez ní na obecný popisek, nikdy na prázdno', () => {
    const { t, te } = i18n('cs')
    expect(jmhzBlockerLabel(t, te, blocker({ code: 'jmhz_unknown_future_code', reason: 'Věta serveru.' }))).toBe('Věta serveru.')
    expect(jmhzBlockerLabel(t, te, blocker({ code: 'jmhz_unknown_future_code', reason: undefined }))).toBe(cs.payroll.jmhz_gate.unlabelled)
  })

  it('chyba serveru v angličtině se překládá podle kódu, v češtině zůstává věta serveru', () => {
    const error = { response: { data: { error: { code: 'jmhz_deferral_regular_frozen', message: 'Řádné hlášení je zmrazené.' } } } }
    const english = i18n('en')
    expect(jmhzErrorMessage(english.t, english.te, 'en', error, 'payroll.jmhz_gate.deferral.defer_failed'))
      .toBe(en.payroll.jmhz_gate.codes.jmhz_deferral_regular_frozen)
    const czech = i18n('cs')
    expect(jmhzErrorMessage(czech.t, czech.te, 'cs', error, 'payroll.jmhz_gate.deferral.defer_failed'))
      .toBe('Řádné hlášení je zmrazené.')
  })

  it('kódy NEMPRI, ZP a registrací mají překlad v server_codes', () => {
    const { te } = i18n('en')
    expect(payrollCodeKey(te, 'sickness_case_conflict')).toBe('payroll.server_codes.sickness_case_conflict')
    expect(payrollCodeKey(te, 'zp_insurer_code_missing')).toBe('payroll.server_codes.zp_insurer_code_missing')
    expect(payrollCodeKey(te, 'jmhz_deferral_conflict')).toBe('payroll.jmhz_gate.codes.jmhz_deferral_conflict')
    expect(payrollCodeKey(te, 'not_a_code')).toBeNull()
  })
})
