import { beforeEach, expect, it, vi } from 'vitest'
const mock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('../client', () => ({ api: mock }))
import { closingApi, type DeferredTax504Decision } from '../closing'
beforeEach(() => {
  vi.clearAllMocks()
  mock.get.mockResolvedValue({ data: { source_hash: 'get-receipt' } })
  mock.post.mockResolvedValue({ data: { source_hash: 'post-receipt' } })
})
it('uses GET for saved state and the separate POST route for structured preview', async () => {
  const decision: DeferredTax504Decision = { method: { enabled: true, version: '504-deferred-own-v1', first_adoption: false, reference: 'SYN', faithful_image_reason: 'Synthetic', standard_departure_reason: 'Synthetic', presentation_reason: 'Synthetic' }, titles: [] }
  expect(await closingApi.deferredTax504Preview(7)).toEqual({ source_hash: 'get-receipt' })
  expect(mock.get).toHaveBeenCalledWith('/accounting/periods/7/closing/deferred-tax-preview')
  expect(await closingApi.deferredTax504Preview(7, decision)).toEqual({ source_hash: 'post-receipt' })
  expect(mock.post).toHaveBeenCalledWith('/accounting/periods/7/closing/deferred-tax-504-preview', decision)
  await closingApi.deferredTax504Run(7, 4, { ...decision, source_hash: 'post-receipt' })
  expect(mock.post).toHaveBeenLastCalledWith('/accounting/periods/7/closing/steps/deferred_tax/run', { row_version: 4, decision_504: { ...decision, source_hash: 'post-receipt' } })
})