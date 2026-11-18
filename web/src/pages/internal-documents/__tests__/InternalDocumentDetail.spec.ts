import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  updateText: vi.fn(),
  removePosted: vi.fn(),
  push: vi.fn(),
}))

vi.mock('@/api/internalDocuments', () => ({
  internalDocumentsApi: { get: m.get, updateText: m.updateText, removePosted: m.removePosted },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => false }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => `${v} Kč` }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '7' } }),
  useRouter: () => ({ push: m.push }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }),
}))

import InternalDocumentDetail from '../InternalDocumentDetail.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'

const ModalStub = { template: '<div><slot /><slot name="footer" /></div>' }

function doc(overrides: Record<string, unknown> = {}) {
  return {
    id: 7, kind: 'offset', title: 'Syntetický zápočet', document_date: '2099-03-30', entry_date: '2099-03-31',
    partner_id: null, partner_name: null, amount: 1500, note: 'Původní', status: 'posted', origin: 'draft',
    document_no: 'ID-2099-0001', journal_entry_id: 55, reversal_entry_id: null, reversal_reason: null,
    posted_at: '2099-03-31', reversed_at: null, lines: [], document_links: [],
    posted_deletion: { allowed: true, needs_ack: false, code: null, message: null },
    ...overrides,
  }
}

function actions(wrapper: ReturnType<typeof shallowMount>): ActionItem[] {
  return wrapper.findComponent(ActionBar).props('actions') as ActionItem[]
}

describe('InternalDocumentDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.get.mockResolvedValue(doc())
  })

  it('u zaúčtovaného dokladu uloží jen změněnou poznámku a popis', async () => {
    m.updateText.mockResolvedValue(doc({ note: 'Nová', title: 'Nový popis' }))
    const wrapper = shallowMount(InternalDocumentDetail, { global: { stubs: { Modal: ModalStub } } })
    await flushPromises()
    actions(wrapper).find(a => a.key === 'edit_text')!.run!()
    await flushPromises()
    await wrapper.get('[data-test="text-note"]').setValue('Nová')
    await wrapper.get('[data-test="text-title"]').setValue('Nový popis')
    await wrapper.get('form#internal-document-text').trigger('submit')
    await flushPromises()
    expect(m.updateText).toHaveBeenCalledWith(7, { note: 'Nová', title: 'Nový popis' })
  })

  it('u stornovaného dokladu nabídne jen poznámku a smazání se zápisem ne', async () => {
    m.get.mockResolvedValue(doc({ status: 'reversed', reversal_entry_id: 56, posted_deletion: undefined }))
    m.updateText.mockResolvedValue(doc({ status: 'reversed', note: 'Proč storno' }))
    const wrapper = shallowMount(InternalDocumentDetail, { global: { stubs: { Modal: ModalStub } } })
    await flushPromises()
    expect(actions(wrapper).find(a => a.key === 'delete_posted')!.show).toBe(false)
    actions(wrapper).find(a => a.key === 'edit_text')!.run!()
    await flushPromises()
    expect(wrapper.find('[data-test="text-title"]').exists()).toBe(false)
    await wrapper.get('[data-test="text-note"]').setValue('Proč storno')
    await wrapper.get('form#internal-document-text').trigger('submit')
    await flushPromises()
    expect(m.updateText).toHaveBeenCalledWith(7, { note: 'Proč storno' })
  })

  it('smazání se zápisem zakáže s důvodem serveru a jinak smaže a vrátí na seznam', async () => {
    m.get.mockResolvedValue(doc({ posted_deletion: { allowed: false, needs_ack: false, code: 'entry_paired', message: 'Spárováno.' } }))
    let wrapper = shallowMount(InternalDocumentDetail, { global: { stubs: { Modal: ModalStub } } })
    await flushPromises()
    const blocked = actions(wrapper).find(a => a.key === 'delete_posted')!
    expect(blocked.disabled).toBe(true)
    expect(blocked.disabledReason).toBe('internal_documents.delete_posted_blocked:{"reason":"Spárováno."}')
    expect(actions(wrapper).find(a => a.key === 'reverse')!.show).toBe(true)

    m.get.mockResolvedValue(doc())
    m.removePosted.mockResolvedValue({ deleted: true, journal_entry_id: 55, number_released: false })
    wrapper = shallowMount(InternalDocumentDetail, { global: { stubs: { Modal: ModalStub } } })
    await flushPromises()
    actions(wrapper).find(a => a.key === 'delete_posted')!.run!()
    await flushPromises()
    await wrapper.get('[data-test="delete-posted-confirm"]').trigger('click')
    await flushPromises()
    expect(m.removePosted).toHaveBeenCalledWith(7, false)
    expect(m.push).toHaveBeenCalledWith({ name: 'accounting-internal-documents' })
  })
})
