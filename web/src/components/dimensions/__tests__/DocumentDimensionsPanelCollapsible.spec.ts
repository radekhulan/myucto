import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-i18n', async importOriginal => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true },
    canEdit: { value: true },
    documentTypes: { value: [{ id: 1, name: 'Projekt', is_active: true, show_on_documents: true }] },
    load: () => Promise.resolve(),
  }),
}))
vi.mock('@/api/dimensions', () => ({
  dimensionsApi: {
    getDocument: vi.fn(() => Promise.resolve({ header: { 1: 5 }, splits: {} })),
    prefill: vi.fn(() => Promise.resolve({ header: {} })),
    saveDocument: vi.fn(),
  },
  compactDimensions: (map: Record<number, number | null>) =>
    Object.fromEntries(Object.entries(map).filter(([, value]) => value != null)),
}))

import DocumentDimensionsPanel from '@/components/dimensions/DocumentDimensionsPanel.vue'

const stubs = { DimensionFields: true, DimensionChips: true, DimensionSplitEditor: true }

function mountPanel(props: Record<string, unknown> = {}) {
  return mount(DocumentDimensionsPanel, {
    props: { docType: 'purchase-invoices', docId: 7, ...props },
    global: { stubs },
    attachTo: document.body,
  })
}

describe('DocumentDimensionsPanel – sbalitelná karta', () => {
  it('bez collapsible zůstává otevřená jako dřív, bez přepínače', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="document-dimensions-toggle"]').exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'DimensionFields' }).exists()).toBe(true)
  })

  it('collapsible je výchozím stavem sbalená a otevře se kliknutím na hlavičku', async () => {
    const wrapper = mountPanel({ collapsible: true })
    await flushPromises()
    const toggle = wrapper.get('[data-test="document-dimensions-toggle"]')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(wrapper.findComponent({ name: 'DimensionFields' }).isVisible()).toBe(false)

    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(wrapper.findComponent({ name: 'DimensionFields' }).isVisible()).toBe(true)
  })

  it('initiallyOpen otevře sbalitelnou kartu hned (chybí povinná dimenze)', async () => {
    const wrapper = mountPanel({ collapsible: true, initiallyOpen: true })
    await flushPromises()
    expect(wrapper.get('[data-test="document-dimensions-toggle"]').attributes('aria-expanded')).toBe('true')
  })
})
