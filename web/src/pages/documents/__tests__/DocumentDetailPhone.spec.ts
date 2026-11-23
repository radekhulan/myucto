import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const m = vi.hoisted(() => ({ get: vi.fn() }))
const phoneRef = ref(false)

vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '7' } }),
  useRouter: () => ({ push: vi.fn(), back: vi.fn() }),
}))
vi.mock('vue-i18n', async () => ({
  ...await vi.importActual<typeof import('vue-i18n')>('vue-i18n'),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => false }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/api/documents', () => ({
  documentsApi: {
    get: m.get,
    previewUrl: (id: number) => `/api/documents/${id}/preview`,
    downloadUrl: (id: number) => `/api/documents/${id}/download`,
  },
}))

import DocumentDetail from '../DocumentDetail.vue'

function pdfDocument() {
  return {
    id: 7, supplier_id: 1, folder_id: null, title: 'ukazka.pdf', description: null, original_name: 'ukazka.pdf',
    sha256: '0'.repeat(64), mime_type: 'application/pdf', size_bytes: 2048, doc_type: 'pdf', source: 'manual',
    parent_document_id: null, signature_for_id: null, text_status: 'ready', thumb_status: 'none', has_thumb: false,
    created_at: '2093-01-01 10:00:00', deleted_at: null, tags: [], links: [], attachments: [], files: [],
    dms_message: null, breadcrumb: [],
  }
}

async function mountDetail() {
  const wrapper = shallowMount(DocumentDetail)
  await flushPromises()
  return wrapper
}

describe('DocumentDetail na telefonu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.get.mockResolvedValue(pdfDocument())
  })

  it('mimo telefon zobrazí PDF v iframe a Stáhnout v hlavičce, bez spodní lišty', async () => {
    phoneRef.value = false
    const wrapper = await mountDetail()
    expect(wrapper.find('iframe').attributes('src')).toBe('/api/documents/7/preview#view=FitH')
    expect(wrapper.findComponent({ name: 'PdfCanvasViewer' }).exists()).toBe(false)
    expect(wrapper.get('[data-test="document-detail-actions"] a').attributes('href')).toBe('/api/documents/7/download')
    expect(wrapper.find('[data-test="document-phone-bar"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="document-preview-fullscreen"]').exists()).toBe(false)
  })

  it('na telefonu kreslí PDF přes PdfCanvasViewer a nabídne lištu se stažením a sdílením', async () => {
    phoneRef.value = true
    const share = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { ...navigator, share, canShare: () => true })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, blob: async () => new Blob(['%PDF'], { type: 'application/pdf' }) }))
    const wrapper = await mountDetail()
    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'PdfCanvasViewer' }).props('src')).toBe('/api/documents/7/preview')
    expect(wrapper.get('[data-test="document-preview-fullscreen"]').attributes('href')).toBe('/api/documents/7/preview')
    expect(wrapper.find('[data-test="document-detail-actions"] a').exists()).toBe(false)
    const bar = wrapper.get('[data-test="document-phone-bar"]')
    expect(bar.classes()).toContain('sticky')
    await bar.get('[data-test="document-phone-share"]').trigger('click')
    await flushPromises()
    expect(share).toHaveBeenCalledTimes(1)
    const payload = share.mock.calls[0]![0] as { files: File[] }
    expect(payload.files[0]!.name).toBe('ukazka.pdf')
    vi.unstubAllGlobals()
  })
})
