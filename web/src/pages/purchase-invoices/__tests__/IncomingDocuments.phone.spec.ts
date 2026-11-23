import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import type { PurchaseInvoiceSubmission } from '@/api/purchaseInvoiceSubmissions'

const m = vi.hoisted(() => ({
  list: vi.fn(), reject: vi.fn(), needsInformation: vi.fn(), remove: vi.fn(),
  success: vi.fn(), error: vi.fn(),
  supplier: { currentSupplierId: 1, availableSuppliers: [] as Array<{ id: number }> },
  route: { query: {} as Record<string, string> },
  push: vi.fn(), replace: vi.fn(), back: vi.fn(),
  phone: { value: true } as { value: boolean },
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
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error, warning: vi.fn() }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: Error) => e.message }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key }),
}))
vi.mock('vue-router', async () => {
  const { reactive } = await import('vue')
  m.route = reactive(m.route)
  return { useRouter: () => ({ push: m.push, replace: m.replace, back: m.back }), useRoute: () => m.route }
})
vi.mock('@/composables/useIsPhone', async () => {
  const { ref } = await import('vue')
  m.phone = ref(true)
  return { useIsPhone: () => m.phone, installPhoneViewport: () => {} }
})
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({ canEdit: { value: false }, documentTypes: { value: [] }, load: vi.fn() }),
}))
vi.mock('@/components/purchase/PdfDropzone.vue', () => ({ default: { name: 'PdfDropzone', template: '<div data-testid="dropzone" />' } }))
vi.mock('@/components/purchase/ExtractionReviewModal.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/dimensions/DimensionFields.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/dimensions/DimensionChips.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))

import IncomingDocuments from '../IncomingDocuments.vue'

function submission(id: number, status: PurchaseInvoiceSubmission['status'] = 'submitted'): PurchaseInvoiceSubmission {
  return {
    id, status, submitted_via: 'staff', original_name: `ukazka-${id}.pdf`, size_bytes: 512,
    created_at: '2093-01-01', replacement_submission_id: null, purchase_invoice_id: null,
    status_reason: null, note: null, dimensions: {}, doc_type: 'pdf',
  } as PurchaseInvoiceSubmission
}

async function mountPage(items: PurchaseInvoiceSubmission[]) {
  m.list.mockResolvedValueOnce({ items })
  const wrapper = shallowMount(IncomingDocuments, {
    global: { stubs: { DocumentPreviewSplit: { template: '<div><slot /></div>' } } },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountPage>>

const detail = (w: Wrapper) => w.find('[data-testid="inbox-phone-detail"]')
const title = (w: Wrapper) => detail(w).get('h2').text()
const position = (w: Wrapper) => w.get('[data-testid="inbox-phone-position"]').text()

async function openItem(w: Wrapper, index: number) {
  await w.findAll('[data-testid="inbox-item"]')[index]!.trigger('click')
  await flushPromises()
}

describe('Příchozí doklady na telefonu', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    m.phone.value = true
    m.supplier.currentSupplierId = 1
    m.route.query = {}
    const navigate = ({ query }: { query: Record<string, string> }) => { m.route.query = query; return Promise.resolve() }
    m.push.mockImplementation(navigate)
    m.replace.mockImplementation(navigate)
    m.back.mockImplementation(() => {
      const query = { ...m.route.query }
      delete query.doc
      m.route.query = query
    })
    vi.stubGlobal('confirm', vi.fn(() => true))
  })

  it('ukáže jen seznam, klepnutí otevře detail přes celou plochu s pořadím a adresou dokladu', async () => {
    const w = await mountPage([submission(1), submission(2), submission(3)])
    expect(detail(w).exists()).toBe(false)
    expect(w.find('h2.font-semibold:not(.text-neutral-800)').exists()).toBe(false)

    await openItem(w, 1)
    expect(detail(w).exists()).toBe(true)
    expect(detail(w).classes()).toContain('fixed')
    expect(title(w)).toBe('ukazka-2.pdf')
    expect(position(w)).toBe('purchase_submissions.phone_position {"n":2,"total":3}')
    expect(m.push).toHaveBeenCalledWith({ query: { doc: '2' } })
    expect(w.find('[data-testid="inbox-phone-actions"]').exists()).toBe(true)
    expect(w.find('[data-testid="inbox-phone-preview"]').exists()).toBe(true)
    expect(w.find('[data-testid="inbox-preview-toggle"]').exists()).toBe(false)
  })

  it('přechází na další a předchozí doklad tlačítky i gestem a hlídá okraje seznamu', async () => {
    const w = await mountPage([submission(1), submission(2), submission(3)])
    await openItem(w, 0)
    expect(w.get('[data-testid="inbox-phone-prev"]').attributes('disabled')).toBeDefined()

    await w.get('[data-testid="inbox-phone-next"]').trigger('click')
    await flushPromises()
    expect(title(w)).toBe('ukazka-2.pdf')
    expect(m.replace).toHaveBeenLastCalledWith({ query: { doc: '2' } })

    const scroll = w.get('[data-testid="inbox-phone-scroll"]')
    await scroll.trigger('pointerdown', { pointerType: 'touch', isPrimary: true, pointerId: 7, clientX: 300, clientY: 200 })
    await scroll.trigger('pointerup', { pointerType: 'touch', isPrimary: true, pointerId: 7, clientX: 150, clientY: 215 })
    await flushPromises()
    expect(title(w)).toBe('ukazka-3.pdf')
    expect(w.get('[data-testid="inbox-phone-next"]').attributes('disabled')).toBeDefined()

    // Svislý pohyb (scroll) ani krátký posun doklad nemění.
    await scroll.trigger('pointerdown', { pointerType: 'touch', isPrimary: true, pointerId: 8, clientX: 150, clientY: 400 })
    await scroll.trigger('pointerup', { pointerType: 'touch', isPrimary: true, pointerId: 8, clientX: 230, clientY: 150 })
    await flushPromises()
    expect(title(w)).toBe('ukazka-3.pdf')

    await scroll.trigger('pointerdown', { pointerType: 'touch', isPrimary: true, pointerId: 9, clientX: 100, clientY: 200 })
    await scroll.trigger('pointerup', { pointerType: 'touch', isPrimary: true, pointerId: 9, clientX: 260, clientY: 210 })
    await flushPromises()
    expect(title(w)).toBe('ukazka-2.pdf')

    await w.get('[data-testid="inbox-phone-prev"]').trigger('click')
    await flushPromises()
    expect(title(w)).toBe('ukazka-1.pdf')
  })

  it('tlačítko Seznam vrátí historii zpět a systémové Zpět detail zavře', async () => {
    const w = await mountPage([submission(1), submission(2)])
    await openItem(w, 0)
    await w.get('[data-testid="inbox-phone-back"]').trigger('click')
    await flushPromises()
    expect(m.back).toHaveBeenCalledTimes(1)
    expect(detail(w).exists()).toBe(false)

    await openItem(w, 1)
    expect(detail(w).exists()).toBe(true)
    m.route.query = {}
    await flushPromises()
    expect(detail(w).exists()).toBe(false)
  })

  it('doklad z adresy otevře detail po načtení seznamu', async () => {
    m.route.query = { doc: '2' }
    const w = await mountPage([submission(1), submission(2)])
    expect(detail(w).exists()).toBe(true)
    expect(title(w)).toBe('ukazka-2.pdf')
  })

  it('po odmítnutí pokračuje dalším dokladem a po posledním se vrátí na seznam', async () => {
    const w = await mountPage([submission(1), submission(2), submission(3)])
    await openItem(w, 1)
    await detail(w).get('textarea').setValue('Duplicitní doklad')
    m.reject.mockResolvedValueOnce(submission(2, 'rejected'))
    m.list.mockResolvedValueOnce({ items: [submission(1), submission(3)] })
    await detail(w).findAll('button').find(b => b.text() === 'purchase_submissions.reject' && !b.attributes('data-testid'))!.trigger('click')
    await flushPromises()
    expect(m.reject).toHaveBeenCalledWith(2, 'Duplicitní doklad')
    expect(title(w)).toBe('ukazka-3.pdf')
    expect(position(w)).toBe('purchase_submissions.phone_position {"n":2,"total":2}')
    expect(m.replace).toHaveBeenLastCalledWith({ query: { doc: '3' } })

    m.remove.mockResolvedValueOnce(undefined)
    m.list.mockResolvedValueOnce({ items: [submission(1)] })
    await detail(w).get('[data-testid="inbox-delete"]').trigger('click')
    await flushPromises()
    expect(title(w)).toBe('ukazka-1.pdf')

    m.remove.mockResolvedValueOnce(undefined)
    m.list.mockResolvedValueOnce({ items: [] })
    await detail(w).get('[data-testid="inbox-delete"]').trigger('click')
    await flushPromises()
    expect(detail(w).exists()).toBe(false)
    expect(m.back).toHaveBeenCalled()
  })

  it('nahrání nabídne dvě velká tlačítka místo plochy pro přetažení', async () => {
    const w = await mountPage([])
    expect(w.find('[data-testid="inbox-phone-upload"]').exists()).toBe(true)
    expect(w.find('[data-testid="inbox-phone-choose"]').exists()).toBe(true)
    expect(w.findComponent({ name: 'PdfDropzone' }).exists()).toBe(false)
  })
})

describe('Příchozí doklady mimo telefon', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    m.phone.value = false
    m.route.query = {}
  })

  it('vykreslí seznam a detail vedle sebe jako dosud, bez adresy dokladu', async () => {
    const w = await mountPage([submission(1), submission(2)])
    expect(detail(w).exists()).toBe(false)
    expect(w.findAll('h2').at(-1)!.text()).toBe('ukazka-1.pdf')
    expect(w.find('[data-testid="inbox-preview-toggle"]').exists()).toBe(true)
    expect(w.find('[data-testid="inbox-phone-actions"]').exists()).toBe(false)
    expect(w.find('section.min-w-0').exists()).toBe(true)

    await openItem(w, 1)
    expect(w.findAll('h2').at(-1)!.text()).toBe('ukazka-2.pdf')
    expect(m.push).not.toHaveBeenCalled()
    expect(m.replace).not.toHaveBeenCalled()
  })

  it('nahrání zůstává s plochou pro přetažení a bez telefonních tlačítek', async () => {
    const w = await mountPage([])
    expect(w.find('[data-testid="inbox-phone-upload"]').exists()).toBe(false)
    expect(w.findComponent({ name: 'PdfDropzone' }).exists()).toBe(true)
  })
})
