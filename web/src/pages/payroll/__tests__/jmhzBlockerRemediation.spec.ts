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

  /**
   * Hlášení vykazuje roční zúčtování za předchozí zdaňovací období. Bez roku
   * a osoby se stránka otevřela na loňském roce podle dnešního data a celém
   * seznamu, ne na zaměstnanci a roce, kterého se nález týká.
   */
  it('nález ročního zúčtování otevře rok před vykazovaným měsícem a osobu', () => {
    const target = (entityType: string, id: number | null) => jmhzRemediationTarget(
      blocker({ code: 'jmhz_annual_request_status_unresolved', entity_type: entityType, remediation: { kind: 'annual_settlement', field: null } }),
      id,
      { period: '2027-02', employments: [{ id: 7, employee_id: 3 }] },
    )
    expect(target('person', 3)).toEqual({ name: 'payroll-annual-settlement', query: { year: '2026', person: '3' } })
    expect(target('employment', 7)).toEqual({ name: 'payroll-annual-settlement', query: { year: '2026', person: '3' } })
    expect(target('run', null)).toEqual({ name: 'payroll-annual-settlement', query: { year: '2026' } })
    expect(jmhzRemediationTarget(
      blocker({ code: 'jmhz_annual_request_status_unresolved', entity_type: 'run', remediation: { kind: 'annual_settlement', field: null } }),
      null,
    )).toEqual({ name: 'payroll-annual-settlement', query: {} })
  })

  it('nález složky otevře její zařazení do JMHZ v číselníku, ne Mzdové vstupy', () => {
    expect(jmhzRemediationTarget(
      blocker({ code: 'component_jmhz_mapping_missing', entity_type: 'component', remediation: { kind: 'components', field: null } }),
      12,
    )).toEqual({ name: 'payroll-components', query: { tab: 'catalog', component: '12', panel: 'jmhz' } })
    expect(jmhzRemediationTarget(
      blocker({ code: 'component_jmhz_manual_review', entity_type: 'run', remediation: { kind: 'components', field: null } }),
      null,
    )).toEqual({ name: 'payroll-components', query: { tab: 'catalog', jmhz: 'manual_review' } })
  })

  it('souhrnný nález průměrů vede na čtvrtletí vykazovaného období', () => {
    expect(jmhzRemediationTarget(
      blocker({ code: 'jmhz_average_hourly_earning_missing', entity_type: 'run', remediation: { kind: 'averages', field: null } }),
      null,
      { period: '2026-08' },
    )).toEqual({ name: 'payroll-absences', query: { tab: 'averages', year: '2026', quarter: '3' } })
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

describe('zúžení číselníku složek z odkazu ?jmhz=', () => {
  it('souhrnný nález otevře jen složky s tímtéž stavem zařazení', async () => {
    const { filterCatalogByJmhz, jmhzCatalogFilter, jmhzComponentQuery } = await import('@/pages/payroll/jmhzBlockerRemediation')
    const states: Record<number, string> = { 1: 'configured', 2: 'missing', 3: 'manual_review' }
    const components = [{ id: 1 }, { id: 2 }, { id: 3 }]
    const stateOf = (component: { id: number }) => ({ status: states[component.id]! })

    const missing = jmhzCatalogFilter(jmhzComponentQuery('component_jmhz_mapping_missing', null).jmhz)
    expect(filterCatalogByJmhz(components, stateOf, missing)).toEqual([{ id: 2 }])
    const review = jmhzCatalogFilter(jmhzComponentQuery('component_jmhz_manual_review', null).jmhz)
    expect(filterCatalogByJmhz(components, stateOf, review)).toEqual([{ id: 3 }])
    expect(filterCatalogByJmhz(components, stateOf, jmhzCatalogFilter('invalid'))).toHaveLength(3)
    expect(filterCatalogByJmhz(components, stateOf, jmhzCatalogFilter(undefined))).toHaveLength(3)
  })
})

describe('odkaz na roční zúčtování z blokace hlášení', () => {
  it('nese rok zúčtování (minulý rok hlášení) a osobu', async () => {
    const { jmhzRemediationTarget } = await import('@/pages/payroll/jmhzBlockerRemediation')
    const target = jmhzRemediationTarget(
      {
        code: 'jmhz_annual_request_status_unresolved',
        entity_type: 'employee',
        entity_id: 5,
        attribute_ids: ['10319'],
        remediation: { kind: 'annual_settlement', field: null },
      } as unknown as PayrollJmhzXmlDryRunBlocker,
      5,
      { period: '2027-02', employments: [] },
    )
    expect(target).toEqual({ name: 'payroll-annual-settlement', query: { year: '2026', person: '5' } })
  })
})
