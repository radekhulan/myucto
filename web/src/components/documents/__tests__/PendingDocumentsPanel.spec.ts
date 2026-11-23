import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({ upload: vi.fn(), addLink: vi.fn() }))

vi.mock('@/api/documents', () => ({ documentsApi: { upload: m.upload, addLink: m.addLink, search: vi.fn() } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v }))
vi.mock('../docFormat', () => ({ docTypeBadge: () => ({ class: '', label: '' }), formatBytes: (n: number) => String(n) }))

import { commitPendingDocuments } from '../PendingDocumentsPanel.vue'

describe('commitPendingDocuments', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.addLink.mockResolvedValue([])
  })

  it('nahraje soubory do složky a připojí nové i vybrané existující dokumenty', async () => {
    m.upload.mockResolvedValue({ root_ids: [11, 12], errors: [] })
    const files = [new File(['a'], 'a.pdf'), new File(['b'], 'b.pdf')]
    const result = await commitPendingDocuments({ files, existing: [{ id: 5 }] }, 'other_item', 77, 'Smlouvy/2026')

    expect(m.upload).toHaveBeenCalledWith(files, { zipMode: 'keep', relpaths: ['Smlouvy/2026', 'Smlouvy/2026'] })
    expect(m.addLink.mock.calls).toEqual([[5, 'other_item', 77], [11, 'other_item', 77], [12, 'other_item', 77]])
    expect(result).toEqual({ failed: 0 })
  })

  it('bez složky nahraje do kořene a spočítá chyby nahrání i připojení', async () => {
    m.upload.mockResolvedValue({ root_ids: [11], errors: [{ name: 'b.pdf' }] })
    m.addLink.mockRejectedValueOnce(new Error('x'))
    const result = await commitPendingDocuments({ files: [new File(['a'], 'a.pdf'), new File(['b'], 'b.pdf')], existing: [] }, 'other_item', 77)

    expect(m.upload).toHaveBeenCalledWith(expect.any(Array), { zipMode: 'keep' })
    expect(result).toEqual({ failed: 2 })
  })

  it('selhání celého nahrání započte všechny soubory a existující dokumenty přesto připojí', async () => {
    m.upload.mockRejectedValue(new Error('down'))
    const result = await commitPendingDocuments({ files: [new File(['a'], 'a.pdf')], existing: [{ id: 5 }] }, 'other_item', 77)

    expect(m.addLink).toHaveBeenCalledWith(5, 'other_item', 77)
    expect(result).toEqual({ failed: 1 })
  })
})
