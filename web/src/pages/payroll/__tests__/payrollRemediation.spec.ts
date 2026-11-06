import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createI18n } from 'vue-i18n'
import { i18nMessages } from '../../../../tests/locales'
import { averageEarningsTarget, eldpRemediation, eldpRemediationCodes, workSummaryRemediation, workSummaryRemediationCodes } from '../payrollRemediation'

describe('mzdové úkony mají konkrétní nápravu', () => {
  it('pokrývá všechny blokace podkladů ELDP a souhrnu docházky', () => {
    for (const [files, catalog] of [
      [['Submission/Eldp/EldpAnnualStatementBuilder.php', 'Submission/Eldp/EldpExcludedPeriodDeriver.php'], eldpRemediationCodes],
      // Odpracovanou dobu ze zdroje souhrnu (intervaly i souhrn importu) hlásí
      // PayrollWorkedTimeSource, zbytek nálezů náhledu builder.
      [['Time/PayrollJmhzWorkMonthSummaryBuilder.php', 'Time/PayrollWorkedTimeSource.php'], workSummaryRemediationCodes],
    ] as const) {
      const codes = new Set<string>()
      for (const file of files) {
        const source = readFileSync(resolve(process.cwd(), `../api/src/Service/Payroll/${file}`), 'utf8')
        for (const match of source.matchAll(/'code'\s*=>\s*'([a-z_]+)'/g)) codes.add(match[1]!)
      }
      expect(codes.size).toBeGreaterThanOrEqual(6)
      expect([...codes].filter(code => !Object.hasOwn(catalog, code))).toEqual([])
    }
  })

  /*
   * Kódy výjimek ELDP (potvrzení, dokument, rok bez pojištění …) chodí panelu
   * jako jediný nález. Bez mapování skončily „neznámou chybou" s odkazem na
   * podporu, přestože se náprava dělá o dvě pole výš ve formuláři.
   */
  it('pokrývá i kódy výjimek evidenčního listu', () => {
    for (const [files, catalog] of [
      [[
        'Submission/Eldp/EldpAnnualStatementBuilder.php',
        'Submission/Eldp/EldpManualCompletionService.php',
        'Submission/Eldp/EldpStatementService.php',
        'Submission/Eldp/EldpSchemaCatalog.php',
        'Submission/Eldp/EldpXmlValidator.php',
        'Submission/Eldp/EldpXmlSerializer.php',
      ], eldpRemediationCodes],
    ] as const) {
      const codes = new Set<string>()
      for (const file of files) {
        const source = readFileSync(resolve(process.cwd(), `../api/src/Service/Payroll/${file}`), 'utf8')
        for (const match of source.matchAll(/Exception\(\s*'(eldp_[a-z_]+)'/g)) codes.add(match[1]!)
      }
      expect(codes.size).toBeGreaterThanOrEqual(6)
      expect([...codes].filter(code => !Object.hasOwn(catalog, code))).toEqual([])
    }
  })

  it('všechny příčiny i postupy mají srozumitelné cs/en překlady', () => {
    for (const locale of ['cs', 'en']) {
      const t = createI18n({ legacy: false, locale, messages: i18nMessages }).global.t
      for (const guidance of [
        ...Object.keys(eldpRemediationCodes).map(code => eldpRemediation({ code, message: '', detail: { period_start: '2025-03-01' } }, 12, 2025)),
        ...Object.keys(workSummaryRemediationCodes).map(code => workSummaryRemediation(code, 12, '2025-03')),
      ]) {
        expect(t(guidance.problemKey)).not.toBe(guidance.problemKey)
        expect(t(guidance.stepKey)).not.toBe(guidance.stepKey)
        expect(t(guidance.actionKey)).not.toBe(guidance.actionKey)
        expect(t(guidance.problemKey)).not.toMatch(/snapshot|manifest|ordinary evidence|ruleset/i)
      }
    }
  })

  it('neposílá chybu integrity do editace zaměstnance', () => {
    expect(eldpRemediation({ code: 'eldp_source_mismatch', message: '' }, 12, 2025).path).toBe('/admin/support')
    expect(eldpRemediation({ code: 'future_code', message: '' }, 12, 2025).path).toBe('/admin/support')
    expect(workSummaryRemediation('future_code', 12, '2025-03').path).toBe('/admin/support')
  })

  it.each([
    ['manual_summary_missing', 'manual_missing'],
    ['manual_segment_invalid', 'manual_values'],
    ['manual_segments_overlap', 'manual_overlap'],
    ['manual_worked_days_invalid', 'manual_days'],
  ])('otevírá podklady hodin správného vztahu a měsíce pro %s', (code, kind) => {
    const guidance = workSummaryRemediation(code, 44, '2026-11-01')
    expect(guidance.path).toBe('/payroll/quick-inputs?employment=44&period=2026-11&hours=1')
    expect(guidance.actionKey).toBe('payroll.remediation.actions.manual_hours')
    expect(guidance.problemKey).toBe(`payroll.remediation.work.problems.${kind}`)
    expect(guidance.stepKey).toBe(`payroll.remediation.work.steps.${kind}`)
  })

  it('rozlišuje nápravu převzatého měsíce od nápravy mzdy v MyÚčtu', () => {
    // Chybějící údaj převzatého měsíce se doplňuje v Kontrole převodu mezd;
    // ve mzdách MyÚčta by nebylo co opravovat, ten měsíc modul nepočítal.
    expect(eldpRemediation({ code: 'eldp_takeover_insurance_days_missing', message: '', detail: { employment_id: 44, period_start: '2025-03-01' } }, 12, 2025).path)
      .toBe('/payroll/imports?tab=reconciliation')
    // Naopak měsíc, který MyÚčto počítá a jen mu chybí schválení, patří do běhu.
    expect(eldpRemediation({ code: 'eldp_takeover_month_not_substitutable', message: '', detail: { period_start: '2025-03-01' } }, 12, 2025).path)
      .toBe('/payroll/runs?period=2025-03')
  })

  it('datum vyhotovení a opravný list opraví na formuláři evidenčního listu', () => {
    for (const code of ['eldp_prepared_on_before_period_end', 'eldp_scope_already_frozen', 'eldp_correction_without_change']) {
      const guidance = eldpRemediation({ code, message: '' }, 12, 2025)
      expect(guidance.path).toBe('/payroll/submissions/eldp')
      expect(guidance.actionKey).toBe('payroll.remediation.actions.eldp_form')
    }
  })

  it('nese konkrétní měsíc absence i období použití průměru', () => {
    expect(eldpRemediation({ code: 'eldp_absence_overlap_unsupported', message: '', detail: { employment_id: 44, period_start: '2025-03-01' } }, 12, 2025).path)
      .toBe('/payroll/absences?employment=44&tab=absences&period=2025-03')
    expect(averageEarningsTarget(12, 2026, 3)).toBe('/payroll/absences?employment=12&tab=averages&year=2026&quarter=3')
    expect(averageEarningsTarget(12, 2026, 9)).toBe('/payroll/absences?employment=12&tab=averages')
  })
})
