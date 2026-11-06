import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import type { PurchaseInvoiceSubmission } from '@/api/purchaseInvoiceSubmissions'

const m = vi.hoisted(() => ({
  list: vi.fn(), reject: vi.fn(), needsInformation: vi.fn(), remove: vi.fn(),
  success: vi.fn(), error: vi.fn(), canWrite: vi.fn((_permission: string) => true),
  supplier: { currentSupplierId: 1, availableSuppliers: [] as Array<{ id: number }> },
}))
vi.mock('@/api/purchaseInvoiceSubmissions', () => ({
  purchaseInvoiceSubmissionsApi: {
    list: m.list, reject: m.reject, needsInformation: m.needsInformation, remove: m.remove,
    previewUrl: (id: number) => `/preview/${id}`, downloadUrl: (id: number) => `/download/${id}`,
  },
}))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  m.supplier = reactive(m.supplier)
  return { useSupplierStore: () => m.supplier }
})
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error, warning: vi.fn() }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: Error) => e.message }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({ canEdit: { value: false }, documentTypes: { value: [] }, load: vi.fn() }),
}))
vi.mock('@/components/purchase/PdfDropzone.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/purchase/ExtractionReviewModal.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/dimensions/DimensionFields.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/dimensions/DimensionChips.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))

import IncomingDocuments from '../IncomingDocuments.vue'

function submission(id: number, status: PurchaseInvoiceSubmission['status'] = 'submitted', via = 'portal'): PurchaseInvoiceSubmission {
  return {
    id, status, submitted_via: via, original_name: `ukazka-${id}.pdf`, size_bytes: 512,
    created_at: '2093-01-01', replacement_submission_id: null, purchase_invoice_id: null,
    status_reason: null, note: null, dimensions: {}, doc_type: 'pdf',
  } as PurchaseInvoiceSubmission
}

async function mountPage(items: PurchaseInvoiceSubmission[]) {
  m.list.mockResolvedValueOnce({ items })
  // Stub bez slotu by schoval celý detail dokladu; tady jde o něj, ne o rozložení.
  const wrapper = shallowMount(IncomingDocuments, {
    global: { stubs: { DocumentPreviewSplit: { template: '<div><slot /></div>' } } },
  })
  await flushPromises()
  return wrapper
}

function button(wrapper: ReturnType<typeof shallowMount>, key: string) {
  return wrapper.findAll('button').find(item => item.text() === `purchase_submissions.${key}`)!
}

describe('Příchozí doklady: odmítnutí', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    m.canWrite.mockReturnValue(true)
    m.supplier.currentSupplierId = 1
    vi.stubGlobal('confirm', vi.fn(() => true))
  })

  it('vyžaduje důvod i u interního podání a po jeho zadání odmítne správný doklad', async () => {
    const wrapper = await mountPage([submission(1, 'submitted', 'staff')])
    expect(button(wrapper, 'reject').attributes('disabled')).toBeDefined()
    await wrapper.get('textarea').setValue('  Do účetnictví nepatří.  ')
    m.reject.mockResolvedValueOnce({ ...submission(1, 'rejected', 'staff'), status_reason: 'Do účetnictví nepatří.' })
    m.list.mockResolvedValueOnce({ items: [] })
    await button(wrapper, 'reject').trigger('click')
    await flushPromises()
    expect(m.reject).toHaveBeenCalledWith(1, 'Do účetnictví nepatří.')
    expect(m.remove).not.toHaveBeenCalled()
  })

  it('umožní odmítnout doklad čekající na doplnění i bez práva mazat', async () => {
    m.canWrite.mockImplementation(permission => permission !== 'documents.inbox.delete')
    const wrapper = await mountPage([submission(1, 'needs_information')])
    expect(wrapper.find('[data-testid="inbox-delete"]').exists()).toBe(false)
    expect(button(wrapper, 'reject')).toBeDefined()
    expect(button(wrapper, 'needs_information')).toBeUndefined()
    await wrapper.get('textarea').setValue('Jde o soukromý výdaj.')
    await (wrapper.vm as unknown as { setReviewStatus: (status: 'needs_information') => Promise<void> }).setReviewStatus('needs_information')
    expect(m.needsInformation).not.toHaveBeenCalled()
    m.reject.mockResolvedValueOnce(submission(1, 'rejected'))
    m.list.mockResolvedValueOnce({ items: [] })
    await button(wrapper, 'reject').trigger('click')
    await flushPromises()
    expect(m.reject).toHaveBeenCalledWith(1, 'Jde o soukromý výdaj.')
  })

  it('hromadné odmítnutí vyžaduje důvod a z označených vyřídí jen nezpracované doklady', async () => {
    const wrapper = await mountPage([submission(1, 'submitted', 'staff'), submission(2, 'needs_information'), submission(3, 'processed')])
    await wrapper.get('[data-testid="inbox-check-all"]').setValue(true)
    const reject = wrapper.get('[data-testid="inbox-bulk-reject"]')
    expect(reject.attributes('disabled')).toBeDefined()
    await wrapper.get('input[placeholder="purchase_submissions.bulk_reason_placeholder"]').setValue('  Duplicitní doklady.  ')
    m.reject.mockResolvedValue({})
    m.list.mockResolvedValueOnce({ items: [] })
    await reject.trigger('click')
    await flushPromises()
    expect(m.reject.mock.calls).toEqual([[1, 'Duplicitní doklady.'], [2, 'Duplicitní doklady.']])
    expect(m.remove).not.toHaveBeenCalled()
  })

  it('při výběru jiného dokladu nepřenáší rozepsaný důvod a opožděná odpověď nevybere původní doklad', async () => {
    let finish!: (item: PurchaseInvoiceSubmission) => void
    m.reject.mockImplementation(() => new Promise(resolve => { finish = resolve }))
    const one = submission(1)
    const two = submission(2)
    const wrapper = await mountPage([one, two])
    await wrapper.get('textarea').setValue('Důvod prvního dokladu')
    await button(wrapper, 'reject').trigger('click')
    await wrapper.findAll('button').find(item => item.text().includes('ukazka-2.pdf'))!.trigger('click')
    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('')
    await wrapper.get('textarea').setValue('Důvod druhého dokladu')
    m.list.mockResolvedValueOnce({ items: [two] })
    finish(submission(1, 'rejected'))
    await flushPromises()
    expect(wrapper.findAll('h2').at(-1)!.text()).toBe('ukazka-2.pdf')
    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('Důvod druhého dokladu')
  })

  it('opožděná chyba po změně firmy se nezobrazí v nové firmě', async () => {
    let fail!: (error: Error) => void
    m.reject.mockImplementation(() => new Promise((_, reject) => { fail = reject }))
    const wrapper = await mountPage([submission(1)])
    await wrapper.get('textarea').setValue('Důvod odmítnutí')
    await button(wrapper, 'reject').trigger('click')
    m.list.mockResolvedValueOnce({ items: [submission(2)] })
    m.supplier.currentSupplierId = 2
    await flushPromises()
    fail(new Error('Chyba předchozí firmy'))
    await flushPromises()
    expect(m.error).not.toHaveBeenCalled()
    expect(wrapper.findAll('h2').at(-1)!.text()).toBe('ukazka-2.pdf')
  })

  it.each(['processing', 'processed', 'rejected'] as const)('nenabídne odmítnutí ve stavu %s', async status => {
    const wrapper = await mountPage([submission(1, status)])
    expect(button(wrapper, 'reject')).toBeUndefined()
  })

  it('přerušené vytěžení nabídne znovu vytěžit i smazat, běžící ne', async () => {
    const running = await mountPage([submission(1, 'processing', 'staff')])
    expect(button(running, 'extract')).toBeUndefined()
    expect(running.find('[data-testid="inbox-delete"]').exists()).toBe(false)

    const stale = await mountPage([{ ...submission(2, 'processing', 'staff'), processing_stale: true }])
    expect(stale.text()).toContain('purchase_submissions.processing_stale')
    expect(button(stale, 'extract')).toBeDefined()
    expect(stale.find('[data-testid="inbox-delete"]').exists()).toBe(true)
  })
})
