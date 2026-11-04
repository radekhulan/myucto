import { describe, expect, it } from 'vitest'
import {
  formatPayrollMinutes,
  payrollMonthCoversFund,
  payrollWallTimeToIso,
} from '@/pages/payroll/payrollTime'

describe('payrollTime', () => {
  it('formátuje fond, rozdíl i záporné minuty bez ztráty znaménka', () => {
    expect(formatPayrollMinutes(0)).toBe('0:00')
    expect(formatPayrollMinutes(485)).toBe('8:05')
    expect(formatPayrollMinutes(-75)).toBe('−1:15')
  })

  it('odvodí offset ze zvoleného IANA pásma, ne z pásma prohlížeče', () => {
    expect(payrollWallTimeToIso('2026-06-01T08:00', 'Europe/Prague'))
      .toBe('2026-06-01T08:00:00+02:00')
    expect(payrollWallTimeToIso('2026-06-01T08:00', 'America/New_York'))
      .toBe('2026-06-01T08:00:00-04:00')
  })

  it('započte do pokrytí fondu svátky a nepřítomnosti, ne jen odpracovanou dobu', () => {
    const month = (
      actual: number,
      unworked: string | null,
      holiday: number | null,
    ) => ({
      summary: { fund_minutes: 10_080, actual_minutes: actual },
      jmhz_work_summary: {
        preview: {
          suggestions: { unworked_total_hours: unworked },
          holiday_millihours: holiday,
        },
      },
    })

    // 21 pracovních dní po 8 h, jeden z nich svátek: 160 h práce + 8 h svátku.
    expect(payrollMonthCoversFund(month(9_600, null, 8_000))).toBe(true)
    // Dovolená 16 h v úhrnu neodpracovaných hodin.
    expect(payrollMonthCoversFund(month(9_120, '16.000', null))).toBe(true)
    expect(payrollMonthCoversFund(month(9_600, null, null))).toBe(false)
    expect(payrollMonthCoversFund({
      summary: { fund_minutes: 10_080, actual_minutes: 10_080 },
      jmhz_work_summary: { preview: null },
    })).toBe(true)
  })

  it('neodešle neplatný ani neexistující lokální čas při přechodu DST', () => {
    expect(payrollWallTimeToIso('není-datum', 'Europe/Prague')).toBe('')
    expect(payrollWallTimeToIso('2026-03-29T02:30', 'Europe/Prague')).toBe('')
  })
})
