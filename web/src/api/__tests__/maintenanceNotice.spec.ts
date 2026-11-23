import { beforeEach, describe, expect, it } from 'vitest'
import {
  dismissMaintenanceNotice,
  dismissedMaintenanceNotices,
  visibleMaintenanceNotices,
  type MaintenanceNotice,
} from '@/api/maintenanceNotice'

const notice = (key: string, endsAt = '2026-11-20T22:00:00Z'): MaintenanceNotice => ({
  key,
  severity: 'warning',
  starts_at: '2026-11-20T20:00:00Z',
  ends_at: endsAt,
  message: { cs: 'Údržba', en: 'Maintenance' },
})

describe('maintenanceNotice', () => {
  beforeEach(() => {
    localStorage.clear()
    dismissedMaintenanceNotices.value = []
  })

  it('zavřená hláška zmizí a zavření přežije obnovení stránky', () => {
    dismissMaintenanceNotice('remote:7@1')

    const now = Date.parse('2026-11-19T12:00:00Z')
    expect(visibleMaintenanceNotices([notice('remote:7@1'), notice('remote:8@1')], dismissedMaintenanceNotices.value, now)
      .map(n => n.key)).toEqual(['remote:8@1'])
    expect(JSON.parse(localStorage.getItem('myinvoice.maintenance_notice.dismissed') ?? '[]')).toEqual(['remote:7@1'])
  })

  it('upravená hláška (nová revize) se po zavření staré ukáže znovu', () => {
    dismissMaintenanceNotice('remote:7@1')

    const visible = visibleMaintenanceNotices([notice('remote:7@2')], dismissedMaintenanceNotices.value, Date.parse('2026-11-19T12:00:00Z'))
    expect(visible).toHaveLength(1)
  })

  it('skončenou hlášku neukáže, ani když ji server ještě vrací', () => {
    expect(visibleMaintenanceNotices([notice('a')], [], Date.parse('2026-11-20T22:00:00Z'))).toEqual([])
  })

  it('pamatuje si jen posledních 20 zavřených klíčů', () => {
    for (let i = 0; i < 25; i++) dismissMaintenanceNotice(`k${i}`)
    expect(dismissedMaintenanceNotices.value).toHaveLength(20)
    expect(dismissedMaintenanceNotices.value[0]).toBe('k5')
  })
})
