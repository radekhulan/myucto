import { describe, expect, it, vi } from 'vitest'

const remove = vi.hoisted(() => vi.fn())
vi.mock('@/api/client', () => ({ api: { delete: remove } }))
import { instanceAdminApi } from './instanceAdmin'

describe('Instance SMTP API', () => {
  it('návrat ke konfiguraci používá administrátorský DELETE s prázdným tělem', async () => {
    const restored = { source: 'configuration', target_id: null, password_set: false }
    remove.mockResolvedValue({ data: restored })
    expect(await instanceAdminApi.resetSmtpOverride()).toEqual(restored)
    expect(remove).toHaveBeenCalledExactlyOnceWith('/admin/instance-smtp/override', { data: {} })
  })
})
