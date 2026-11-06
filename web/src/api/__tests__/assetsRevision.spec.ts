import { beforeEach, describe, expect, it, vi } from 'vitest'
const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('../client', () => ({ api: { post } }))
import { assetsApi } from '../assets'

const payload = { effective_from: '2030-02', remaining_months: 2, reason: 'Synthetic reassessment', expected_revision: 2 }
beforeEach(() => vi.resetAllMocks())

describe('asset depreciation revisions API', () => {
  it('requests a preview without supplying a client-calculated schedule', async () => {
    const preview = { revision: 3, calendar_start_month: 7 }
    post.mockResolvedValue({ data: preview })
    await expect(assetsApi.previewRevision(9, payload)).resolves.toBe(preview)
    expect(post).toHaveBeenCalledWith('/accounting/assets/9/depreciation/revision/preview', payload)
  })

  it('saves the expected revision and fiscal calendar from the preview', async () => {
    const result = { asset: { id: 9 }, warnings: [] }
    post.mockResolvedValue({ data: result })
    await expect(assetsApi.revise(9, { ...payload, expected_calendar_start_month: 7, expected_calendar_fingerprint: 'synthetic-calendar-v1' })).resolves.toBe(result)
    expect(post).toHaveBeenCalledWith('/accounting/assets/9/depreciation/revision', { ...payload, expected_calendar_start_month: 7, expected_calendar_fingerprint: 'synthetic-calendar-v1' })
  })

  it('preserves stale conflicts for the UI to handle', async () => {
    const error = { response: { status: 409, data: { error: { code: 'imported_plan_revision_stale' } } } }
    post.mockRejectedValue(error)
    await expect(assetsApi.revise(9, { ...payload, expected_calendar_start_month: 7, expected_calendar_fingerprint: 'synthetic-calendar-v1' })).rejects.toBe(error)
  })
})
