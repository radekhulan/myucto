import { beforeEach, describe, expect, it, vi } from 'vitest'
const post = vi.hoisted(() => vi.fn())
vi.mock('../client', () => ({ api: { post } }))
import { updateApi } from '../update'

describe('Potvrzení přerušení práce při aktualizaci superadmina', () => {
  beforeEach(() => { post.mockReset(); post.mockResolvedValue({ data: { status: 'queued' } }) })
  it('odešle výslovné potvrzení aktivních relací', async () => {
    await updateApi.trigger('6.0.1', true)
    expect(post).toHaveBeenCalledWith('/admin/update/trigger', { target_version: '6.0.1', confirm_active_sessions: true })
  })
  it('bez potvrzení nepředstírá souhlas s přerušením práce', async () => {
    await updateApi.trigger('6.0.1')
    expect(post).toHaveBeenCalledWith('/admin/update/trigger', { target_version: '6.0.1', confirm_active_sessions: false })
  })
})
