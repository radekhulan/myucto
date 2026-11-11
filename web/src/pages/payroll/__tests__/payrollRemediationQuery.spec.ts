import { describe, expect, it } from 'vitest'
import {
  componentJmhzFilterFromQuery,
  componentMatchesJmhzFilter,
  payrollPeopleFilterFromQuery,
  runRemediationHref,
} from '../payrollRemediationQuery'

describe('parametry odkazů z nálezů', () => {
  it('doplní období jen agendám, které ho čtou, a nepřepíše zadané', () => {
    expect(runRemediationHref('/payroll/time', '2026-08-01')).toBe('/payroll/time?period=2026-08')
    expect(runRemediationHref('/payroll/quick-inputs?employment=3', '2026-08-01')).toBe('/payroll/quick-inputs?employment=3&period=2026-08')
    expect(runRemediationHref('/payroll/submissions/jmhz', '2026-08')).toBe('/payroll/submissions/jmhz?period=2026-08')
    expect(runRemediationHref('/payroll/submissions?x=1#jmhz-ordinary-evidence', '2026-08')).toBe('/payroll/submissions?x=1&period=2026-08#jmhz-ordinary-evidence')
    expect(runRemediationHref('/payroll/runs?period=2026-07', '2026-08')).toBe('/payroll/runs?period=2026-07')
    expect(runRemediationHref('/payroll/people?person=3', '2026-08')).toBe('/payroll/people?person=3')
    expect(runRemediationHref('/payroll/time', null)).toBe('/payroll/time')
    expect(runRemediationHref('/payroll/time', 'nesmysl')).toBe('/payroll/time')
  })

  it('filtr zaměstnanců z adresy přijme jen známou hodnotu', () => {
    expect(payrollPeopleFilterFromQuery({ filter: 'blocking_data' })).toBe('blocking_data')
    expect(payrollPeopleFilterFromQuery({ filter: 'cokoli' })).toBe('active')
    expect(payrollPeopleFilterFromQuery({})).toBe('active')
  })

  it('filtr zařazení složek odpovídá rozdělení nálezů na serveru', () => {
    expect(componentJmhzFilterFromQuery({ jmhz: 'invalid' })).toBe('invalid')
    expect(componentJmhzFilterFromQuery({ jmhz: 'configured' })).toBeNull()
    expect(componentMatchesJmhzFilter('missing', { jmhz_treatment: 'included' }, { status: 'missing' })).toBe(true)
    expect(componentMatchesJmhzFilter('missing', { jmhz_treatment: 'included' }, { status: 'outside_breakdown' })).toBe(false)
    expect(componentMatchesJmhzFilter('manual_review', { jmhz_treatment: 'manual_review' }, { status: 'manual_review' })).toBe(true)
    expect(componentMatchesJmhzFilter('invalid', { jmhz_treatment: 'excluded' }, { status: 'excluded' })).toBe(false)
    expect(componentMatchesJmhzFilter('invalid', { jmhz_treatment: 'unknown' as never }, { status: 'excluded' })).toBe(true)
  })
})
