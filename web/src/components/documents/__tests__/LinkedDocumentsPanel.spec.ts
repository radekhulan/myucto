import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  byEntity: vi.fn(),
  upload: vi.fn(),
  addLink: vi.fn(),
  removeLink: vi.fn(),
  search: vi.fn(),
  canWrite: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  push: vi.fn(),
}))

vi.mock('@/api/documents', () => ({
  documentsApi: {
    byEntity: m.byEntity,
    upload: m.upload,
    addLink: m.addLink,
    removeLink: m.removeLink,
    search: m.search,
    thumbUrl: (id: number) => `/thumb/${id}`,
    previewUrl: (id: number) => `/preview/${id}`,
    downloadUrl: (id: number) => `/download/${id}`,
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.toastSuccess, error: m.toastError }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: m.push }) }))

import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'

function docItem(id: number) {
  return { id, title: `Účtenka ${id}`, doc_type: 'pdf', size_bytes: 1000, has_thumb: false }
}

async function mountPanel(props: Record<string, unknown> = {}) {
  const wrapper = mount(LinkedDocumentsPanel, {
    props: { entityType: 'cash_document', entityId: 42, ...props },
  })
  await flushPromises()
  return wrapper
}

describe('LinkedDocumentsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.byEntity.mockResolvedValue([docItem(7)])
    m.addLink.mockResolvedValue([])
    m.removeLink.mockResolvedValue([])
  })

  it('načte dokumenty entity a nabídne náhled i odpojení', async () => {
    const wrapper = await mountPanel()
    expect(m.byEntity).toHaveBeenCalledWith('cash_document', 42)
    expect(wrapper.text()).toContain('Účtenka 7')
    expect(wrapper.find('[data-testid="linked-docs-preview"]').attributes('href')).toBe('/preview/7')

    await wrapper.find('button[title="documents.unlink_hint"]').trigger('click')
    await flushPromises()
    expect(m.removeLink).toHaveBeenCalledWith(7, 'cash_document', 42)
  })

  it('bez `uploadable` tlačítko nahrání nenabízí', async () => {
    const wrapper = await mountPanel()
    expect(wrapper.find('[data-testid="linked-docs-upload"]').exists()).toBe(false)
  })

  it('nahraný sken rovnou naváže na doklad (jen dokumenty nejvyšší úrovně)', async () => {
    m.upload.mockResolvedValue({ created: 3, root_ids: [11, 12], skipped: [], errors: [] })
    const wrapper = await mountPanel({ uploadable: true, title: 'linked_documents.title' })
    expect(wrapper.text()).toContain('linked_documents.title')

    const input = wrapper.find('[data-testid="linked-docs-file"]')
    const file = new File(['%PDF-1.4'], 'uctenka.pdf', { type: 'application/pdf' })
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await flushPromises()

    expect(m.upload).toHaveBeenCalledWith([file], { zipMode: 'keep' })
    expect(m.addLink.mock.calls).toEqual([[11, 'cash_document', 42], [12, 'cash_document', 42]])
    expect(m.byEntity).toHaveBeenCalledTimes(2)
    expect(m.toastSuccess).toHaveBeenCalledWith('linked_documents.uploaded')
  })

  it('bez práva nahrávat dokumenty tlačítko skryje i s `uploadable`', async () => {
    m.canWrite.mockImplementation((p: string) => p !== 'documents.upload')
    const wrapper = await mountPanel({ uploadable: true })
    expect(wrapper.find('[data-testid="linked-docs-upload"]').exists()).toBe(false)
  })

  it('selhání nahrání není tiché', async () => {
    m.upload.mockRejectedValue(new Error('boom'))
    const wrapper = await mountPanel({ uploadable: true })
    const input = wrapper.find('[data-testid="linked-docs-file"]')
    Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'a.pdf')], configurable: true })
    await input.trigger('change')
    await flushPromises()
    expect(m.addLink).not.toHaveBeenCalled()
    expect(m.toastError).toHaveBeenCalledWith('linked_documents.upload_failed')
  })
})
