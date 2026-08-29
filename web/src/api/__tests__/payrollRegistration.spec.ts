import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollRegistrationEventInput } from '@/api/payroll'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: {
    get: m.get,
    post: m.post,
    put: m.put,
  },
}))

import { payrollApi } from '@/api/payroll'

describe('payroll REGZEC event API', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('lists and approves immutable events in the selected environment', async () => {
    m.get.mockResolvedValueOnce({ data: { items: [{ id: 91 }] } })
    await expect(payrollApi.employmentRegistrationEvents(5, 'production'))
      .resolves.toEqual([{ id: 91 }])
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/submissions/registration/5/events',
      { params: { environment: 'production' } },
    )

    const payload: PayrollRegistrationEventInput = {
      environment: 'test',
      interaction: 'variable_symbol_transfer',
      effective_on: '2026-08-26',
      source_reference: 'transfer-decision-4',
      new_variable_symbol: '9990005678',
    }
    m.post.mockResolvedValueOnce({ data: { id: 92 } })
    await payrollApi.approveEmploymentRegistrationEvent(5, payload)
    expect(m.post).toHaveBeenCalledWith(
      '/payroll/submissions/registration/5/events',
      payload,
    )
  })

  it('passes the selected event to both preview and prepare', async () => {
    m.get.mockResolvedValueOnce({ data: { interaction: 'change' } })
    await payrollApi.previewEmploymentRegistration(5, 'test', 91)
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/submissions/registration/5',
      { params: { environment: 'test', event_id: 91 } },
    )

    m.post.mockResolvedValueOnce({ data: { interaction: 'change' } })
    await payrollApi.prepareEmploymentRegistration(5, 'test', 91)
    expect(m.post).toHaveBeenCalledWith(
      '/payroll/submissions/registration/5',
      { environment: 'test', event_id: 91 },
    )
  })

  it('loads and version-saves the authoritative A1 profile', async () => {
    m.get.mockResolvedValueOnce({ data: { profile: { row_version: 2 } } })
    await expect(payrollApi.employmentRegistrationA1Profile(5))
      .resolves.toEqual({ row_version: 2 })
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/submissions/registration/5/a1-profile',
    )

    const payload = { effective_on: '2026-08-14', row_version: 2 }
    m.put.mockResolvedValueOnce({ data: { profile: { row_version: 3 } } })
    await expect(payrollApi.saveEmploymentRegistrationA1Profile(5, payload))
      .resolves.toEqual({ row_version: 3 })
    expect(m.put).toHaveBeenCalledWith(
      '/payroll/submissions/registration/5/a1-profile',
      payload,
    )
  })

  /**
   * Přepočet detekce je POST, ne GET: zakládá návrhy povinností s běžící
   * osmidenní lhůtou, takže to není bezpečná operace, kterou by směl
   * zopakovat prefetch prohlížeče.
   */
  it('recomputes reportable-change detection through POST', async () => {
    m.get.mockResolvedValueOnce({ data: {} })
    m.post.mockResolvedValueOnce({
      data: {
        as_of: '2026-08-29',
        reason_code: null,
        proposals: [{ id: 7, duty_kind: 'regzec_change', fileable: true }],
        without_baseline: {},
      },
    })

    const detection = await payrollApi.detectEmploymentRegistrationChanges(5, 'production')

    expect(detection.proposals[0].id).toBe(7)
    expect(m.post).toHaveBeenCalledWith(
      '/payroll/submissions/registration/5/changes',
      { environment: 'production' },
    )
    expect(m.get).not.toHaveBeenCalled()
  })

  /** Ohlášení je jedno kliknutí — posílá se jen prostředí, žádný formulář. */
  it('files a detected change with a single call', async () => {
    m.post.mockResolvedValueOnce({ data: { event: { id: 42 }, proposal_id: 7 } })

    await expect(payrollApi.fileEmploymentRegistrationChange(5, 7))
      .resolves.toEqual({ event: { id: 42 }, proposal_id: 7 })
    expect(m.post).toHaveBeenCalledWith(
      '/payroll/submissions/registration/5/changes/7/file',
      { environment: 'test' },
    )
  })

  /**
   * Ruční vyřízení vyžaduje důvod: nesplněná zákonná lhůta, která zmizí
   * bez vysvětlení, je horší než nesplněná lhůta, která je vidět.
   */
  it('closes a proposal manually only with a reason', async () => {
    m.post.mockResolvedValueOnce({ data: { proposal_id: 7, status: 'dismissed' } })

    await payrollApi.dismissEmploymentRegistrationChange(
      5,
      7,
      'Podáno formulářem pojišťovny.',
      'production',
    )
    expect(m.post).toHaveBeenCalledWith(
      '/payroll/submissions/registration/5/changes/7/dismiss',
      { environment: 'production', note: 'Podáno formulářem pojišťovny.' },
    )
  })
})
