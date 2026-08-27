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

  it('na úzkém displeji přesune akce pod název dokumentu', async () => {
    const wrapper = shallowMount(DocumentDetail)
    await flushPromises()

    expect(wrapper.get('[data-test="document-detail-header"]').classes()).toContain('flex-wrap')
    expect(wrapper.get('[data-test="document-detail-actions"]').classes()).toContain('basis-full')
    expect(wrapper.get('h1').classes()).toContain('break-all')
  })

  it('zobrazí ZFO metadata a všechny přílohy se bezpečným preview a stažením', async () => {
    m.get.mockResolvedValueOnce({
      ...document(2605, 'datova-zprava-1752953337.zfo'),
      mime_type: 'application/octet-stream',
      doc_type: 'zfo',
      dms_message: {
        dm_id: '1752953337',
        direction: 'received',
        sender_box_id: 'abc1234',
        sender_name: 'Syntetický odesílatel',
        sender_address: null,
        recipient_box_id: 'def5678',
        recipient_name: 'Syntetický příjemce',
        recipient_address: null,
        annotation: 'Syntetická datová zpráva',
        sender_ref_number: null,
        recipient_ref_number: null,
        dm_type: 'datova_zprava',
        dm_status: 'delivered',
        delivery_time: '2026-08-27 10:00:00',
        acceptance_time: null,
      },
      attachments: [
        {
          ...document(2606, 'potvrzeni.pdf'),
          doc_type: 'pdf',
          mime_type: 'application/pdf',
        },
        {
          ...document(2607, 'oznameni.html'),
          title: '<img src=x onerror=alert(1)>',
          mime_type: 'application/octet-stream',
        },
      ],
    })

    const wrapper = shallowMount(DocumentDetail)
    await flushPromises()

    expect(wrapper.text()).toContain('documents.dms.title')
    expect(wrapper.text()).toContain('1752953337')
    expect(wrapper.text()).toContain('potvrzeni.pdf')
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>')
    expect(wrapper.html()).not.toContain('<img src=x onerror=alert(1)>')

    const downloadUrls = wrapper.findAll('a')
      .map(anchor => anchor.attributes('href'))
    expect(downloadUrls).toContain('/api/documents/2605/download')
    expect(downloadUrls).toContain('/api/documents/2606/download')
    expect(downloadUrls).toContain('/api/documents/2607/download')
    expect(wrapper.find('iframe').attributes('src')).toBe(
      '/api/documents/2606/preview#view=FitH',
    )
  })
})
