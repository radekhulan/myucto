import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

// Náhled originálu v okně kontroly vytěžení (DocumentSidePreview v režimu embedded).
const m = vi.hoisted(() => ({
  invoices: {} as Record<number, Record<string, unknown>>,
  prefs: { value: {} as { flags?: Record<string, boolean> } },
  patchPagePrefs: vi.fn(),
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ RouterLink: { props: ['to'], template: '<a><slot /></a>' } }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isClientRole: false, canWrite: () => true }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: false },
    canEdit: { value: false },
    documentTypes: { value: [] },
    types: { value: [] },
    values: { value: [] },
    typeById: { value: new Map() },
    valueLabel: () => '',
    load: () => Promise.resolve(),
  }),
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => m.prefs,
  patchPagePrefs: m.patchPagePrefs,
}))
vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: {
    get: (id: number) => Promise.resolve(m.invoices[id]),
    expenseSuggestions: () => Promise.resolve({ items: {} }),
    pdfUrl: (id: number, inline = false) => `/api/purchase-invoices/${id}/pdf${inline ? '?inline=1' : ''}`,
  },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: () => Promise.resolve([]) } }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: () => Promise.resolve([]) } }))
vi.mock('@/components/ui/Modal.vue', () => ({
  default: { props: ['title', 'widthClass'], template: '<div class="modal" :data-width="widthClass"><slot /><slot name="footer" /></div>' },
}))
vi.mock('@/components/purchase/ExtractionWarningText.vue', () => ({ default: { template: '<div />' } }))

import ExtractionReviewModal from '@/components/purchase/ExtractionReviewModal.vue'
import { stubSplitWidth } from '@/components/documents/__tests__/previewSplitTestUtils'

function invoice(id: number, overrides: Record<string, unknown> = {}) {
  return {
    id,
    status: 'draft',
    vendor_id: 30,
    project_id: null,
    vendor_company_name: 'Syntetický dodavatel',
    vendor_invoice_number: `FV-${id}`,
    varsymbol: null,
    issue_date: '2096-06-15',
    total_with_vat: 1210,
    currency: 'CZK',
    extraction_warning: 'Rozdíl součtů',
    extraction_review: null,
    review: { reasons: ['extraction_warning'], details: {} },
    items: [{ id: 100 + id, order_index: 0, description: 'Položka', quantity: 1, unit_price_without_vat: 1000, total_without_vat: 1000, expense_kind: null }],
    locked: null,
    pdf_path: `doc-${id}.pdf`,
    pdf_original_name: `faktura-${id}.pdf`,
    ...overrides,
  }
}

function stubViewport(fits: boolean): void {
  stubSplitWidth(fits ? 1200 : 700)
}

async function mountModal(ids: number[]) {
  const wrapper = mount(ExtractionReviewModal, { props: { invoiceIds: ids, onlyFlagged: false }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

function skipButton(wrapper: Awaited<ReturnType<typeof mountModal>>) {
  return wrapper.findAll('button').find(b => b.text().includes('purchase_invoice.extraction_review.skip'))!
}

describe('ExtractionReviewModal — náhled originálu', () => {
  beforeEach(() => {
    m.prefs = { value: {} }
    m.patchPagePrefs.mockReset()
    m.invoices = {
      1: invoice(1),
      2: invoice(2, { pdf_path: null, pdf_original_name: null }),
    }
  })
  afterEach(() => {
    vi.restoreAllMocks()
    document.body.innerHTML = ''
  })

  it('na širokém okně ukáže PDF vedle formuláře a po Přeskočit přepne na další doklad', async () => {
    stubViewport(true)
    const wrapper = await mountModal([1, 2])
    expect(wrapper.find('.modal').attributes('data-width')).toBe('max-w-[112rem]')
    const panel = wrapper.find('[data-test="document-side-preview"]')
    expect(panel.exists()).toBe(true)
    expect(wrapper.find('[data-test="document-preview-splitter"]').element.parentElement!.classList).toContain('sticky')
    // Panel v modálu nesmí nést značku, podle které Modal odsazuje backdrop.
    expect(panel.attributes('data-side-preview')).toBeUndefined()
    expect(wrapper.find('iframe').attributes('src')).toBe('/api/purchase-invoices/1/pdf?inline=1#view=FitH')

    await skipButton(wrapper).trigger('click')
    await flushPromises()
    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.find('[data-test="document-side-preview-empty"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('na úzkém okně ukáže PDF pod formulářem', async () => {
    stubViewport(false)
    const wrapper = await mountModal([1])
    const panel = wrapper.find('[data-test="document-side-preview"]')
    expect(panel.exists()).toBe(true)
    expect(panel.classes()).toContain('w-full')
    expect(wrapper.find('[data-test="document-preview-splitter"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('volba náhledu pod formulářem vrátí okno na běžnou šířku', async () => {
    stubViewport(true)
    const wrapper = await mountModal([1])
    await wrapper.find('[data-test="document-preview-placement-below"]').trigger('click')
    expect(wrapper.find('.modal').attributes('data-width')).toBe('max-w-5xl')
    expect(wrapper.find('[data-test="document-side-preview"]').exists()).toBe(true)
    expect(m.patchPagePrefs).toHaveBeenCalledWith('purchase_invoices', { options: { review_preview_placement: 'below' } })
    wrapper.unmount()
  })

  it('přepínač náhled skryje a volbu si zapamatuje', async () => {
    stubViewport(true)
    const wrapper = await mountModal([1])
    await wrapper.find('[data-test="review-preview-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="document-side-preview"]').exists()).toBe(false)
    expect(wrapper.find('.modal').attributes('data-width')).toBe('max-w-5xl')
    expect(m.patchPagePrefs).toHaveBeenCalledWith('purchase_invoices', { flags: { review_preview_open: false } })
    wrapper.unmount()
  })

  it('uložená volba zavřeného náhledu má přednost před výchozím otevřeným stavem', async () => {
    stubViewport(true)
    m.prefs = { value: { flags: { review_preview_open: false } } }
    const wrapper = await mountModal([1])
    expect(wrapper.find('[data-test="document-side-preview"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('když stránka už ukazuje originál vedle sebe, druhý náhled ani přepínač nevykreslí', async () => {
    stubViewport(true)
    const pagePreview = document.createElement('aside')
    pagePreview.setAttribute('data-side-preview', '')
    document.body.appendChild(pagePreview)
    const wrapper = await mountModal([1])
    expect(wrapper.find('[data-test="document-side-preview"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-preview-toggle"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
