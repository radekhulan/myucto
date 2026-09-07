import { beforeEach, describe, expect, it, vi } from 'vitest'
import { payrollBankSubmissionsApi } from '../payrollBankSubmissions'

const m = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('../client', () => ({ api: m }))
describe('payrollBankSubmissionsApi', () => {
  beforeEach(() => vi.clearAllMocks())
  it('reads stored status without a mutation', async () => {
    const state = { submission: null, connections: [], blocked_reason: null }
    m.get.mockResolvedValue({ data: state })
    expect(await payrollBankSubmissionsApi.status(7)).toEqual(state)
    expect(m.get).toHaveBeenCalledExactlyOnceWith('/payroll/payments/batches/7/bank-submission')
    expect(m.post).not.toHaveBeenCalled()
  })
  it('sends only the connection identifier to the saved batch', async () => {
    m.post.mockResolvedValue({ data: { created: true, submission: { id: 3 } } })
    await payrollBankSubmissionsApi.submit(7, 4)
    expect(m.post).toHaveBeenCalledExactlyOnceWith('/payroll/payments/batches/7/bank-submission', { connection_id: 4 })
  })
})
