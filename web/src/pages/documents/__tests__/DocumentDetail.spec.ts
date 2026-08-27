import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  route: { params: { id: '2605' } },
  get: vi.fn(),
  routerPush: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('vue-router', async () => {
  const { reactive } = await import('vue')
  m.route = reactive(m.route)
  return {
    useRoute: () => m.route,
    useRouter: () => ({ push: m.routerPush, back: vi.fn() }),
  }
})

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => false }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: m.toastError }),
}))

vi.mock('@/api/documents', () => ({
  documentsApi: {
    get: m.get,
    previewUrl: (id: number) => `/api/documents/${id}/preview`,
    downloadUrl: (id: number) => `/api/documents/${id}/download`,
  },
}))

import DocumentDetail from '../DocumentDetail.vue'

function document(id: number, title: string) {
  return {
    id,
    supplier_id: 1,
    folder_id: null,
    title,
    description: null,
    original_name: title,
    sha256: '0'.repeat(64),
    mime_type: 'text/html',
    size_bytes: 128,
    doc_type: 'other',
    source: id === 2605 ? 'manual' : 'zfo_extract',
    parent_document_id: id === 2605 ? null : 2605,
    signature_for_id: null,
    text_status: 'ready',
    thumb_status: 'none',
    has_thumb: false,
    created_at: '2026-08-27 00:18:00',
    deleted_at: null,
    tags: [],
    links: [],
    attachments: [],
    files: [],
    dms_message: null,
    breadcrumb: [],
  }
}

describe('DocumentDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.route.params.id = '2605'
    m.get.mockImplementation(async (id: number) => document(
      id,
      id === 2605 ? 'datova-zprava-1752953337.zfo' : 'DŮLEŽITÉ_OZNÁMENÍ.html',
    ))
  })

  it('na stejné routě znovu načte detail po přechodu ze ZFO na přílohu', async () => {
    const wrapper = shallowMount(DocumentDetail)
    await flushPromises()

    expect(m.get).toHaveBeenCalledWith(2605)
    expect(wrapper.text()).toContain('datova-zprava-1752953337.zfo')

    m.route.params.id = '2607'
    await flushPromises()

    expect(m.get).toHaveBeenLastCalledWith(2607)
    expect(wrapper.text()).toContain('DŮLEŽITÉ_OZNÁMENÍ.html')
  })
})
