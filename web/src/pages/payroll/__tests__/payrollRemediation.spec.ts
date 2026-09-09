import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import { averageEarningsTarget, eldpRemediation, eldpRemediationCodes, workSummaryRemediation, workSummaryRemediationCodes } from '../payrollRemediation'

describe('mzdové úkony mají konkrétní nápravu', () => {
  it('pokrývá všechny blokace podkladů ELDP a souhrnu docházky', () => {
    for (const [files, catalog] of [
      [['Submission/Eldp/EldpAnnualStatementBuilder.php', 'Submission/Eldp/EldpExcludedPeriodDeriver.php'], eldpRemediationCodes],
      [['Time/PayrollJmhzWorkMonthSummaryBuilder.php'], workSummaryRemediationCodes],
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

  it('všechny příčiny i postupy mají srozumitelné cs/en překlady', () => {
    for (const locale of ['cs', 'en']) {
      const t = createI18n({ legacy: false, locale, messages: { cs, en } }).global.t
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

  it('nese konkrétní měsíc absence i období použití průměru', () => {
    expect(eldpRemediation({ code: 'eldp_absence_overlap_unsupported', message: '', detail: { employment_id: 44, period_start: '2025-03-01' } }, 12, 2025).path)
      .toBe('/payroll/absences?employment=44&tab=absences&period=2025-03')
    expect(averageEarningsTarget(12, 2026, 3)).toBe('/payroll/absences?employment=12&tab=averages&year=2026&quarter=3')
    expect(averageEarningsTarget(12, 2026, 9)).toBe('/payroll/absences?employment=12&tab=averages')
  })
})
