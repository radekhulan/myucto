import { describe, expect, it } from 'vitest'
import { isTimeSurchargeDelta } from '../payrollTimeSurchargeDelta'

describe('isTimeSurchargeDelta', () => {
  it('označí rozdílový vstup příplatku z docházky', () => {
    expect(isTimeSurchargeDelta({ source_kind: 'time', external_id: 'surcharge:weekend:2026-09-01:2' })).toBe(true)
    expect(isTimeSurchargeDelta({ source_kind: 'time', external_id: 'surcharge:overtime_wage:2026-09-01:3' })).toBe(true)
  })

  it('původní vstup ani jiné zdroje neoznačí', () => {
    expect(isTimeSurchargeDelta({ source_kind: 'time', external_id: 'surcharge:weekend:2026-09-01:1' })).toBe(false)
    expect(isTimeSurchargeDelta({ source_kind: 'manual', external_id: 'surcharge:weekend:2026-09-01:2' })).toBe(false)
    expect(isTimeSurchargeDelta({ source_kind: 'time', external_id: null })).toBe(false)
    expect(isTimeSurchargeDelta({ source_kind: 'time', external_id: 'standby:2026-09-01:2' })).toBe(false)
  })
})
