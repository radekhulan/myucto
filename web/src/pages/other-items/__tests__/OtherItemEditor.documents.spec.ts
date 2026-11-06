import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  create: vi.fn(),
  commit: vi.fn(),
  settingsGet: vi.fn(),
  routerPush: vi.fn(),
  toastError: vi.fn(),
  canReadDocuments: true,
}))

vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))
vi.mock('@/api/otherItems', () => ({
  otherItemsApi: { get: vi.fn(), update: vi.fn(), create: m.create },
  otherItemKindsApi: { list: vi.fn().mockResolvedValue([]), savePostingProfiles: vi.fn() },
}))
vi.mock('@/api/otherItemSettings', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/otherItemSettings')>()),
  otherItemSettingsApi: { get: m.settingsGet, update: vi.fn() },
}))
vi.mock('@/components/documents/PendingDocumentsPanel.vue', () => ({
  default: { name: 'PendingDocumentsPanel', props: ['modelValue', 'folderPath', 'title'], emits: ['update:modelValue'], template: '<div class="pending" />' },
  commitPendingDocuments: m.commit,
}))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_evidence' } }) }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true, canRead: (key: string) => key !== 'documents' || m.canReadDocuments }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: vi.fn() }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: { side: 'receivable' } }),
  useRouter: () => ({ push: m.routerPush, replace: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useDocumentDimensions', async () => {
  const { ref } = await import('vue')
  return {
    useDocumentDimensions: () => ({
      enabled: ref(false), canEdit: ref(false), header: ref({}), hasAutoFilled: ref(false),
      load: vi.fn(), save: vi.fn(), watchDefaults: vi.fn(),
    }),
  }
})

import OtherItemEditor from '../OtherItemEditor.vue'

async function fillAndSubmit(wrapper: ReturnType<typeof shallowMount>) {
  await wrapper.get('input[maxlength="255"]').setValue('Prodej pozemku Polešovice')
  await wrapper.get('input[type="number"]').setValue('185000')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('OtherItemEditor dokumenty nového dokladu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canReadDocuments = true
    m.create.mockResolvedValue({ id: 77 })
    m.commit.mockResolvedValue({ failed: 0 })
    m.routerPush.mockResolvedValue(undefined)
    m.settingsGet.mockResolvedValue({ document_folder: 'Smlouvy/{YYYY}/{MM}', document_folder_default: '', is_default: false })
  })

  it('po vytvoření nahraje a připojí vybrané dokumenty do složky z nastavení', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const panel = wrapper.findComponent({ name: 'PendingDocumentsPanel' })
    expect(panel.props('folderPath')).toMatch(/^Smlouvy\/\d{4}\/\d{2}$/)
    const file = new File(['%PDF'], 'kupni-smlouva.pdf', { type: 'application/pdf' })
    panel.vm.$emit('update:modelValue', { files: [file], existing: [{ id: 5 }] })
    await fillAndSubmit(wrapper)

    expect(m.commit).toHaveBeenCalledWith({ files: [file], existing: [{ id: 5 }] }, 'other_item', 77, panel.props('folderPath'))
    expect(m.create.mock.invocationCallOrder[0]).toBeLessThan(m.commit.mock.invocationCallOrder[0])
    expect(m.routerPush).toHaveBeenCalledWith({ name: 'other-item-detail', params: { id: 77 } })
  })

  it('bez dokumentů nic nenahrává a selhání nahrání ohlásí, doklad ale zůstane uložený', async () => {
    let wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await fillAndSubmit(wrapper)
    expect(m.commit).not.toHaveBeenCalled()

    m.commit.mockResolvedValue({ failed: 1 })
    wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    wrapper.findComponent({ name: 'PendingDocumentsPanel' }).vm.$emit('update:modelValue', { files: [new File(['x'], 'a.pdf')], existing: [] })
    await fillAndSubmit(wrapper)
    expect(m.toastError).toHaveBeenCalledWith('pending_documents.failed')
    expect(m.routerPush).toHaveBeenCalledTimes(2)
  })

  it('bez práva na dokumenty panel nezobrazí ani nečte nastavení', async () => {
    m.canReadDocuments = false
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    expect(wrapper.findComponent({ name: 'PendingDocumentsPanel' }).exists()).toBe(false)
    expect(m.settingsGet).not.toHaveBeenCalled()
  })
})
