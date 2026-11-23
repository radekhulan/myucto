import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { BankTransaction } from '@/api/bank'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'

vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: vi.fn() }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: null }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: false }, canEdit: { value: false }, documentTypes: { value: [] }, load: () => Promise.resolve(),
  }),
}))
vi.mock('vue-router', () => ({ useRouter: () => ({}), RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))

import BankTransactionRow from '@/components/bank/BankTransactionRow.vue'

function actions(): BankTransactionActions {
  return {
    canRunDetailAction: () => true,
    expandedSuggestions: ref(new Set()), expandedDocs: ref(new Set()), toggleSuggestion: vi.fn(), toggleDocs: vi.fn(),
    suggestionFor: () => null, reviewingSuggestion: ref(false), acceptTxSuggestion: vi.fn(), rejectTxSuggestion: vi.fn(),
    startMatch: vi.fn(), openCreate: vi.fn(), openRequestDoc: vi.fn(), ignoreTx: vi.fn(), unmatchTx: vi.fn(),
    textDetail: ref(null),
  } as unknown as BankTransactionActions
}

const tx = {
  id: 41, statement_id: 4, amount: 500, currency: 'CZK', posted_at: '2026-05-05', match_status: 'unmatched',
  variable_symbol: '123', constant_symbol: null, specific_symbol: null, counterparty_account: null, counterparty_bank: null,
  counterparty_name: 'Odběratel', description: null, bank_ref: null, matched_invoice_id: null, matched_at: null,
} as unknown as BankTransaction

function mountRow(layout: 'desktop' | 'mobile', selectable: boolean, selected = false) {
  return mount(BankTransactionRow, {
    props: { tx, actions: actions(), layout, isDoubleEntry: false, selectable, selected },
    global: {
      stubs: {
        BankTransactionJournalLink: true, RowActionsMenu: true, PostingStatusBadge: true, WhyChip: true, PostingRowActions: true,
        MatchSuggestionPanel: true, LinkedDocumentsPanel: true, DimensionChips: true, DocumentDimensionsPanel: true,
      },
    },
  })
}

describe('BankTransactionRow – výběr pro hromadné akce', () => {
  it('zaškrtávátko je první buňkou řádku a hlásí id pohybu', async () => {
    const wrapper = mountRow('desktop', true, true)
    await flushPromises()
    const firstCell = wrapper.get('tr').findAll('td')[0]!
    const box = firstCell.get('[data-test="bank-row-select"]')
    expect((box.element as HTMLInputElement).checked).toBe(true)
    await box.trigger('change')
    expect(wrapper.emitted('toggle-select')).toEqual([[41]])
    wrapper.unmount()
  })

  it('mobilní karta má zaškrtávátko před částkou', async () => {
    const wrapper = mountRow('mobile', true)
    await flushPromises()
    const box = wrapper.get('[data-test="bank-card-select"]')
    await box.trigger('change')
    expect(wrapper.emitted('toggle-select')).toEqual([[41]])
    wrapper.unmount()
  })

  it.each(['desktop', 'mobile'] as const)('bez výběru (detail výpisu) zaškrtávátko chybí (%s)', async layout => {
    const wrapper = mountRow(layout, false)
    await flushPromises()
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
