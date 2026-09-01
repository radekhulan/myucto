import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { JournalEntryWithLines } from '@/api/accounting'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: ref('cs-CZ'),
    t: (key: string) => key,
  }),
}))

const journalForDocumentMock = vi.fn()
vi.mock('@/api/accounting', () => ({
  accountingApi: { journalForDocument: (...args: unknown[]) => journalForDocumentMock(...args) },
}))

// Vnořené komponenty si tahají data samy (Souvisí) nebo mají vlastní testy
// (rozpad na účty) — tady jde jen o to, kdy se sekce vůbec ukáže a co pošle dál.
vi.mock('@/components/accounting/JournalLinesTable.vue', () => ({
  default: { name: 'JournalLinesTable', props: ['lines', 'dense'], template: '<div class="lines-table" />' },
}))
vi.mock('@/components/accounting/JournalRelatedPanel.vue', () => ({
  default: { name: 'JournalRelatedPanel', props: ['entryId', 'showPreview'], template: '<div class="related" />' },
}))

import DocumentPostingPanel from '@/components/accounting/DocumentPostingPanel.vue'

function entry(id: number, overrides: Partial<JournalEntryWithLines> = {}): JournalEntryWithLines {
  return {
    id,
    supplier_id: 1,
    period_id: 1,
    entry_date: '2026-04-13',
    document_date: '2026-04-13',
    document_no: `FV-${id}`,
    description: 'Vydaná faktura',
    source_type: 'invoice',
    source_id: 271,
    posted_at: '2026-04-13T10:00:00',
    posted_by: 1,
    reversed_by: null,
    row_version: 1,
    created_at: '2026-04-13T10:00:00',
    updated_at: '2026-04-13T10:00:00',
    lines: [
      { id: 1, entry_id: id, supplier_id: 1, account_id: 10, side: 'debit', amount: 243933.58, currency_code: null, fx_rate: null, amount_foreign: null, cost_center: null, line_no: 1, account_code: '311.100', account_name: 'Pohledávky z obchodních vztahů' },
      { id: 2, entry_id: id, supplier_id: 1, account_id: 20, side: 'credit', amount: 243933.58, currency_code: null, fx_rate: null, amount_foreign: null, cost_center: null, line_no: 2, account_code: '602.100', account_name: 'Tržby z prodeje služeb' },
    ],
    ...overrides,
  }
}

function mountPanel() {
  return mount(DocumentPostingPanel, {
    props: { source: 'invoices' as const, docId: 271 },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

describe('DocumentPostingPanel', () => {
  it('nezaúčtovaný doklad sekci vůbec nevykreslí', async () => {
    journalForDocumentMock.mockResolvedValueOnce([])
    const wrapper = mountPanel()
    await flushPromises()

    expect(journalForDocumentMock).toHaveBeenCalledWith('invoices', 271)
    expect(wrapper.text()).toBe('')
  })

  it('selhání načtení detail dokladu neshodí — sekce zůstane skrytá', async () => {
    journalForDocumentMock.mockRejectedValueOnce(new Error('500'))
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.text()).toBe('')
  })

  it('volitelný účetní obsah zpřístupní sbalenou sekci i bez zápisu', async () => {
    journalForDocumentMock.mockResolvedValueOnce([])
    const wrapper = mount(DocumentPostingPanel, {
      props: { source: 'purchase-invoices', docId: 271, alwaysVisible: true },
      slots: { default: '<div class="classification">Účetní klasifikace</div>' },
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('accounting.journal.document_posting.title')
    expect((wrapper.get('.classification').element.parentElement as HTMLElement).style.display).toBe('none')

    await wrapper.get('button').trigger('click')
    expect((wrapper.get('.classification').element.parentElement as HTMLElement).style.display).toBe('')
  })

  it('zaúčtovaný doklad ukáže sbalenou sekci a rozbalí se až na klik', async () => {
    journalForDocumentMock.mockResolvedValueOnce([entry(64157)])
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.text()).toContain('accounting.journal.document_posting.title')
    // Sbaleno: obsah je v DOM, ale schovaný přes v-show.
    expect(wrapper.find('.lines-table').exists()).toBe(true)
    expect((wrapper.find('.lines-table').element.parentElement?.parentElement as HTMLElement).style.display).toBe('none')

    await wrapper.get('button').trigger('click')
    expect((wrapper.find('.lines-table').element.parentElement?.parentElement as HTMLElement).style.display).toBe('')
    expect(wrapper.findComponent({ name: 'JournalRelatedPanel' }).props('entryId')).toBe(64157)
  })

  it('protizápis se označí jako storno', async () => {
    journalForDocumentMock.mockResolvedValueOnce([
      entry(64157, { reversed_by: 64160 }),
      entry(64160, { document_no: 'ST-64160', source_id: null }),
    ])
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('button').trigger('click')

    const badges = wrapper.findAll('span').filter(s => s.text() === 'accounting.journal.document_posting.reversal')
    expect(badges).toHaveLength(1)
  })
})
