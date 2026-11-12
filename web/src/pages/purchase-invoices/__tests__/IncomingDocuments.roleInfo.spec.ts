import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(), reject: vi.fn(), needsInformation: vi.fn(), remove: vi.fn(),
  success: vi.fn(), error: vi.fn(), canWrite: vi.fn((_permission: string) => true), canRead: vi.fn((_permission: string) => true), query: {} as Record<string, string>,
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
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite, canRead: m.canRead }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error, warning: vi.fn() }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: Error) => e.message }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }), useRoute: () => ({ query: m.query }) }))
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

async function mountPage() {
  m.list.mockResolvedValueOnce({ items: [] })
  const wrapper = shallowMount(IncomingDocuments, {
    global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
  })
  await flushPromises()
  return wrapper
}

describe('Příchozí doklady: vysvětlení role a zdroj', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    m.canWrite.mockReturnValue(true)
    m.canRead.mockReturnValue(true)
    m.query = {}
  })

  it('vysvětlí, že stránka je pro účetní, a odkáže na předání dokladů klientem i na doklady z e-mailu', async () => {
    const wrapper = await mountPage()
    const box = wrapper.get('[data-testid="inbox-role-info"]')
    expect(box.text()).toContain('purchase_submissions.role_text')
    expect(box.get('[data-testid="inbox-role-portal"]').attributes('href')).toBe('/portal/purchase-invoice-submissions')
    expect(box.get('[data-testid="inbox-role-email"]').attributes('href')).toBe('/purchase-invoices/email')
  })

  it('bez práva předávat doklady odkaz na portál neukáže, vysvětlení ano', async () => {
    m.canRead.mockImplementation(permission => permission !== 'documents.submit')
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="inbox-role-portal"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="inbox-role-email"]').exists()).toBe(true)
  })

  it('převezme zdroj e-mail z odkazu a načte jen doklady z e-mailu', async () => {
    m.query = { source: 'email' }
    const wrapper = await mountPage()
    expect(m.list).toHaveBeenLastCalledWith('submitted', 'email')
    expect((wrapper.get('[data-testid="inbox-source"]').element as HTMLSelectElement).value).toBe('email')
  })

  it('neznámý zdroj v odkazu ignoruje', async () => {
    m.query = { source: 'neco' }
    await mountPage()
    expect(m.list).toHaveBeenLastCalledWith('submitted', undefined)
  })
})
