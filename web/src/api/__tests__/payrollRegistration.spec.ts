import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollRegistrationEventInput } from '@/api/payroll'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: {
    get: m.get,
    post: m.post,
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
})
