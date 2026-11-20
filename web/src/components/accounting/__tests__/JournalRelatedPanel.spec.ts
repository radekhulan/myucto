import { ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => key }),
}))

const relatedMock = vi.fn()
const notesMock = vi.fn()
const entryMock = vi.fn(async (_id: number) => ({ lines: [] as unknown[] }))
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    getJournalRelated: (...args: unknown[]) => relatedMock(...args),
    getEntry: (id: number) => entryMock(id),
    listJournalNotes: (...args: unknown[]) => notesMock(...args),
  },
}))
const canReadMock = vi.fn((_permission: string) => true)
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: (p: string) => canReadMock(p) }) }))
vi.mock('@/components/accounting/JournalLinesTable.vue', () => ({
  default: { name: 'JournalLinesTable', props: ['lines', 'dense', 'contextDate'], template: '<div />' },
}))

import JournalRelatedPanel from '@/components/accounting/JournalRelatedPanel.vue'

function payment(entryId: number | null) {
  return {
    relation: 'payment', source_type: 'bank', source_id: 77, entry_id: entryId, title: 'BV1', subtitle: null,
    date: '2026-09-09', amount: 179, allocated_amount: 179, currency: 'CZK', route: null, permission: 'bank',
  }
}

function mountPanel() {
  return mount(JournalRelatedPanel, {
    props: { entryId: 10 },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

function otherItem() {
  return {
    relation: 'document', source_type: 'other_item', source_id: 41, entry_id: null, title: 'OP-1', subtitle: 'Partner',
    date: '2026-09-01', amount: 1000, allocated_amount: 400, currency: 'CZK', permission: 'other_items',
    route: { name: 'other-item-detail', params: { id: 41 } }, entry_date: null, entry_document_no: null, entry_posted: false,
  }
}

function mountWithLinks() {
  return mount(JournalRelatedPanel, {
    props: { entryId: 10 },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
  })
}

describe('JournalRelatedPanel', () => {
  afterEach(() => {
    canReadMock.mockImplementation(() => true)
    entryMock.mockResolvedValue({ lines: [] })
    notesMock.mockReset()
  })

  it('popisky typu a štítku pro ostatní položky existují v obou locale', () => {
    for (const locale of [cs, en]) {
      const journal = (locale as unknown as { accounting: { journal: { source: Record<string, string>, related: Record<string, string> } } }).accounting.journal
      expect(journal.source.other_item).toBeTruthy()
      expect(journal.source.gopay).toBeTruthy()
      expect(journal.related.posted_by_this_entry).toBeTruthy()
    }
  })

  it('ostatní položka má proklik na detail jen s právem other_items', async () => {
    notesMock.mockResolvedValue([])
    relatedMock.mockResolvedValueOnce({ items: [otherItem()], truncated: false })
    const wrapper = mountWithLinks()
    await flushPromises()
    expect(wrapper.text()).toContain('accounting.journal.related.allocated')
    expect(wrapper.find('[data-to*="other-item-detail"]').exists()).toBe(true)
    expect(canReadMock).toHaveBeenCalledWith('other_items')

    canReadMock.mockImplementation((p: string) => p !== 'other_items')
    relatedMock.mockResolvedValueOnce({ items: [otherItem()], truncated: false })
    const denied = mountWithLinks()
    await flushPromises()
    expect(denied.find('[data-to*="other-item-detail"]').exists()).toBe(false)
  })

  it('výdaj plně uhrazený platbou nehlásí alokovanou částku (znaménko se nesrovnává)', async () => {
    notesMock.mockResolvedValue([])
    relatedMock.mockResolvedValueOnce({ items: [{ ...payment(null), amount: -500, allocated_amount: 500 }], truncated: false })
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.text()).not.toContain('accounting.journal.related.allocated')
  })

  it('po přepnutí náhledu na zápis protějšku nevykreslí jeho řádky ani poznámky z cache podruhé', async () => {
    notesMock.mockResolvedValue([{ id: 5, entry_id: 20, body: 'poznámka protějšku', pinned: false }])
    entryMock.mockResolvedValue({ lines: [{ id: 1 }] })
    relatedMock.mockResolvedValueOnce({ items: [{ ...otherItem(), entry_id: 20, entry_posted: true }], truncated: false })
    const wrapper = mountWithLinks()
    await flushPromises()
    expect(wrapper.findAllComponents({ name: 'JournalLinesTable' })).toHaveLength(1)
    expect(wrapper.find('[data-test="related-entry-notes"]').exists()).toBe(true)

    relatedMock.mockResolvedValueOnce({ items: [{ ...otherItem(), entry_id: 20, entry_posted: true }], truncated: false })
    await wrapper.setProps({ entryId: 20 })
    await flushPromises()
    expect(wrapper.find('[data-test="related-posted-by-this-entry"]').exists()).toBe(true)
    expect(wrapper.findAllComponents({ name: 'JournalLinesTable' })).toHaveLength(0)
    expect(wrapper.find('[data-test="related-entry-notes"]').exists()).toBe(false)
  })

  it('nezaúčtovaný prohlížený zápis netvrdí „zaúčtováno tímto zápisem“', async () => {
    notesMock.mockResolvedValue([])
    relatedMock.mockResolvedValueOnce({ items: [{ ...otherItem(), entry_id: 10, entry_posted: false }], truncated: false })
    const wrapper = mountWithLinks()
    await flushPromises()
    expect(wrapper.find('[data-test="related-posted-by-this-entry"]').exists()).toBe(false)
  })

  it('záporná alokace u příjmu se stejnou absolutní částkou zůstává vidět', async () => {
    notesMock.mockResolvedValue([])
    relatedMock.mockResolvedValueOnce({ items: [{ ...payment(null), amount: 1000, allocated_amount: -1000 }], truncated: false })
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.text()).toContain('accounting.journal.related.allocated')
  })

  it('doklad zaúčtovaný prohlíženým zápisem má štítek místo odkazu na sebe a nenačítá vlastní řádky', async () => {
    notesMock.mockReset()
    notesMock.mockResolvedValue([])
    entryMock.mockClear()
    relatedMock.mockResolvedValueOnce({ items: [{ ...otherItem(), entry_id: 10, entry_posted: true }], truncated: false })
    const wrapper = mountWithLinks()
    await flushPromises()
    expect(wrapper.find('[data-test="related-posted-by-this-entry"]').exists()).toBe(true)
    expect(wrapper.find('[data-to*="entry_id"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('accounting.journal.related.not_posted')
    expect(notesMock).not.toHaveBeenCalled()
    expect(entryMock).not.toHaveBeenCalled()
  })

  it('ukáže poznámku zápisu úhrady i u dokladu', async () => {
    relatedMock.mockResolvedValueOnce({ items: [payment(29216)], truncated: false })
    notesMock.mockResolvedValueOnce([{ id: 1, entry_id: 29216, body: 'test', pinned: false }])
    const wrapper = mountPanel()
    await flushPromises()
    expect(notesMock).toHaveBeenCalledWith(29216)
    expect(wrapper.find('[data-test="related-entry-notes"]').text()).toContain('test')
  })

  it('proforma bez zápisu má neutrální štítek, ne „Nezaúčtováno"', async () => {
    relatedMock.mockResolvedValueOnce({
      items: [
        { ...payment(null), relation: 'document', source_type: 'invoice', source_id: 5, postable: false },
        { ...payment(null), relation: 'document', source_type: 'invoice', source_id: 6, postable: true },
      ],
      truncated: false,
    })
    const wrapper = mountPanel()
    await flushPromises()
    const text = wrapper.text()
    expect(wrapper.findAll('[data-test="related-advance-not-postable"]')).toHaveLength(1)
    expect(text).toContain('accounting.journal.related.advance_not_postable')
    expect(text.split('accounting.journal.related.not_posted').length - 1).toBe(1)
  })

  it('nezaúčtovaný protějšek poznámky nenačítá', async () => {
    notesMock.mockClear()
    relatedMock.mockResolvedValueOnce({ items: [payment(null)], truncated: false })
    const wrapper = mountPanel()
    await flushPromises()
    expect(notesMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="related-entry-notes"]').exists()).toBe(false)
  })

  it('na detailu zálohy nevypíše znovu zápisy ze sekce Zaúčtování ani samotnou zálohu', async () => {
    notesMock.mockResolvedValue([])
    relatedMock.mockResolvedValueOnce({
      items: [
        { ...payment(null), relation: 'document', source_type: 'invoice', source_id: 900, title: 'ZALOHA', postable: false },
        { ...payment(64655), relation: 'document', source_type: 'invoice', source_id: 901, title: 'KONECNA' },
        { ...payment(64999), relation: 'payment', source_type: 'bank', source_id: 78, title: 'JINA_PLATBA' },
      ],
      truncated: false,
    })
    const wrapper = mount(JournalRelatedPanel, {
      props: { entryId: 64654, hideEntryIds: [64654, 64655], hideDocument: { sourceType: 'invoice', sourceId: 900 } },
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()
    const text = wrapper.text()
    expect(text).not.toContain('ZALOHA')
    expect(text).not.toContain('KONECNA')
    expect(text).toContain('JINA_PLATBA')
  })

  it('když jsou všechny protějšky vidět jinde, panel se nevykreslí', async () => {
    relatedMock.mockResolvedValueOnce({ items: [payment(64655)], truncated: false })
    const wrapper = mount(JournalRelatedPanel, {
      props: { entryId: 64654, hideEntryIds: [64654, 64655] },
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()
    expect(wrapper.find('section').exists()).toBe(false)
  })
})
