import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

/**
 * Předvolby zobrazení „Rozbalovat sekci Zaúčtování / Dimenze v detailu dokladů":
 * řídí jen počáteční stav sekce, ruční rozbalení na detailu je nemění.
 */
const m = vi.hoisted(() => ({
  release: null as null | (() => void),
  patchDisplayPrefs: vi.fn(),
}))

vi.mock('@/composables/useUserPrefs', () => ({
  // Předvolby přijdou, až je test pustí — simuluje pozdní GET /user/preferences.
  ensurePrefsLoaded: () => new Promise<void>(resolve => { m.release = resolve }),
  patchDisplayPrefs: m.patchDisplayPrefs,
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => key }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/workspace/supplierContext', () => ({ getWindowSupplierScope: () => ({ supplierId: 1, windowId: 'window-test' }) }))

const journalForDocument = vi.fn()
vi.mock('@/api/accounting', () => ({ accountingApi: { journalForDocument: (...a: unknown[]) => journalForDocument(...a) } }))
vi.mock('@/components/accounting/JournalLinesTable.vue', () => ({
  default: { name: 'JournalLinesTable', props: ['lines', 'contextDate', 'compactBelow'], template: '<div class="lines-table" />' },
}))
vi.mock('@/components/accounting/JournalRelatedPanel.vue', () => ({ default: { name: 'JournalRelatedPanel', template: '<div />' } }))
vi.mock('@/components/accounting/JournalEntryNotes.vue', () => ({ default: { name: 'JournalEntryNotes', template: '<div />' } }))
vi.mock('@/components/accounting/PostingOriginRow.vue', () => ({
  default: { name: 'PostingOriginRow', template: '<div />', methods: { reload: () => undefined } },
}))
vi.mock('@/components/accounting/RepostModal.vue', () => ({ default: { name: 'RepostModal', template: '<div />' } }))

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

import DocumentPostingPanel from '@/components/accounting/DocumentPostingPanel.vue'
import DocumentDimensionsPanel from '@/components/dimensions/DocumentDimensionsPanel.vue'
import { setLoadedDisplayPrefs, storedDisplayPrefs } from '@/composables/useDisplayPrefs'

const entry = {
  id: 1, entry_date: '2026-04-13', document_no: 'FP-1', description: 'Přijatá faktura', reversed_by: null,
  lines: [
    { id: 1, side: 'debit', amount: 100, account_id: 1, account_code: '518', account_name: 'Služby' },
    { id: 2, side: 'credit', amount: 100, account_id: 2, account_code: '321', account_name: 'Dodavatelé' },
  ],
}

function mountPosting() {
  return mount(DocumentPostingPanel, {
    props: { source: 'purchase-invoices' as const, docId: 7 },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, WorkspaceTeleport: { template: '<div><slot /></div>' } } },
    attachTo: document.body,
  })
}

function mountDimensions(props: Record<string, unknown> = {}) {
  return mount(DocumentDimensionsPanel, {
    props: { docType: 'purchase-invoices', docId: 7, collapsible: true, ...props },
    global: { stubs: { DimensionFields: true, DimensionChips: true, DimensionSplitEditor: true } },
    attachTo: document.body,
  })
}

// Dynamický import modulu předvoleb potřebuje víc než jedno kolo mikroúloh.
const tick = () => new Promise(resolve => setTimeout(resolve, 0))

async function arrive(prefs: Record<string, unknown>) {
  await tick()
  setLoadedDisplayPrefs(prefs)
  m.release?.()
  await flushPromises()
  await tick()
  await flushPromises()
}

describe('Předvolby zobrazení v detailu dokladu', () => {
  beforeEach(() => {
    setLoadedDisplayPrefs({})
    m.release = null
    m.patchDisplayPrefs.mockReset()
    journalForDocument.mockReset()
    journalForDocument.mockResolvedValue([entry])
  })
  afterEach(() => { document.body.innerHTML = '' })

  it('Zaúčtování je bez předvolby sbalené jako dosud', async () => {
    const wrapper = mountPosting()
    await arrive({})
    expect(wrapper.get('[data-test="document-posting-toggle"]').attributes('aria-expanded')).toBe('false')
    expect(wrapper.get('[data-test="document-posting-body"]').isVisible()).toBe(false)
  })

  it('Zaúčtování se s předvolbou rozbalí, i když předvolby přijdou až po vykreslení', async () => {
    const wrapper = mountPosting()
    await flushPromises()
    expect(wrapper.get('[data-test="document-posting-body"]').isVisible()).toBe(false)
    await arrive({ posting_open: true })
    expect(wrapper.get('[data-test="document-posting-body"]').isVisible()).toBe(true)
  })

  it('ruční sbalení Zaúčtování předvolbu nemění a pozdní předvolba ho nepřebije', async () => {
    const wrapper = mountPosting()
    await flushPromises()
    await wrapper.get('[data-test="document-posting-toggle"]').trigger('click')
    await wrapper.get('[data-test="document-posting-toggle"]').trigger('click')
    await arrive({ posting_open: true })
    expect(wrapper.get('[data-test="document-posting-body"]').isVisible()).toBe(false)
    expect(m.patchDisplayPrefs).not.toHaveBeenCalled()
    expect(storedDisplayPrefs.value).toEqual({ posting_open: true })
  })

  it('rozpad na účty dostane práh úzké karty', async () => {
    setLoadedDisplayPrefs({ posting_open: true })
    const wrapper = mountPosting()
    await arrive({ posting_open: true })
    expect(wrapper.getComponent({ name: 'JournalLinesTable' }).props('compactBelow')).toBe(880)
  })

  it('Dimenze jsou bez předvolby sbalené, s předvolbou rozbalené', async () => {
    const closed = mountDimensions()
    await arrive({})
    expect(closed.get('[data-test="document-dimensions-toggle"]').attributes('aria-expanded')).toBe('false')
    closed.unmount()

    const opened = mountDimensions()
    await arrive({ dimensions_open: true })
    expect(opened.get('[data-test="document-dimensions-toggle"]').attributes('aria-expanded')).toBe('true')
  })

  it('ruční sbalení Dimenzí předvolbu nemění', async () => {
    setLoadedDisplayPrefs({ dimensions_open: true })
    const wrapper = mountDimensions()
    await arrive({ dimensions_open: true })
    const toggle = wrapper.get('[data-test="document-dimensions-toggle"]')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(m.patchDisplayPrefs).not.toHaveBeenCalled()
    expect(storedDisplayPrefs.value).toEqual({ dimensions_open: true })
  })

  it('karta bez sbalování (pokladní doklad, majetek) zůstává otevřená bez ohledu na předvolbu', async () => {
    const wrapper = mountDimensions({ collapsible: false })
    await arrive({ dimensions_open: false })
    expect(wrapper.find('[data-test="document-dimensions-toggle"]').exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'DimensionFields' }).exists()).toBe(true)
  })
})
