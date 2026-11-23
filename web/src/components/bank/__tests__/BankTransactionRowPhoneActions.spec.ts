import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { BankTransaction } from '@/api/bank'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'

const phoneRef = ref(false)
vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))
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
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'

function actions(): BankTransactionActions {
  return {
    canRunDetailAction: () => true,
    expandedSuggestions: ref(new Set()), expandedDocs: ref(new Set()), toggleSuggestion: vi.fn(), toggleDocs: vi.fn(),
    suggestionFor: () => null, reviewingSuggestion: ref(false), acceptTxSuggestion: vi.fn(), rejectTxSuggestion: vi.fn(),
    startMatch: vi.fn(), openCreate: vi.fn(), openRequestDoc: vi.fn(), ignoreTx: vi.fn(), unmatchTx: vi.fn(),
    textDetail: ref(null),
  } as unknown as BankTransactionActions
}

const notice = (superseded: boolean) => ({
  id: 21, statement_id: 4, source: 'gpc', amount: 1000, currency: 'CZK', posted_at: '2026-05-05',
  match_status: 'unmatched', ignore_origin: superseded ? 'superseded' : null, notice_superseded: superseded,
  variable_symbol: '2026001', constant_symbol: null, specific_symbol: null, counterparty_account: null, counterparty_bank: null,
  counterparty_name: 'Ukázkový odběratel', description: null, bank_ref: null, matched_invoice_id: null, matched_at: null,
}) as unknown as BankTransaction

function mountRow(layout: 'desktop' | 'mobile', row: BankTransaction, isDoubleEntry = false) {
  return mount(BankTransactionRow, {
    props: { tx: row, actions: actions(), layout, isDoubleEntry },
    global: {
      stubs: {
        BankTransactionJournalLink: true, RowActionsMenu: true, PostingStatusBadge: true, WhyChip: true, PostingRowActions: true,
        MatchSuggestionPanel: true, LinkedDocumentsPanel: true, DimensionChips: true, DocumentDimensionsPanel: true,
      },
    },
  })
}

describe('BankTransactionRow — akce mobilní karty na telefonu', () => {
  it('mimo telefon drží odkaz do deníku, jednu akci, „…" a oko', async () => {
    phoneRef.value = false
    const wrapper = mountRow('mobile', notice(false))
    await flushPromises()
    expect(wrapper.find('[data-test="tx-phone-actions"]').exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'BankTransactionJournalLink' }).exists()).toBe(true)
    expect(wrapper.find('button[aria-label="bank.show_transaction_text"]').exists()).toBe(true)
    expect(wrapper.findComponent(RowActionsMenu).props('inlineCount')).toBe(1)
    wrapper.unmount()
  })

  it('na telefonu dá jednu hlavní akci a zbytek včetně textu pohybu do „…"', async () => {
    phoneRef.value = true
    const wrapper = mountRow('mobile', notice(false))
    await flushPromises()
    const bar = wrapper.get('[data-test="tx-phone-actions"]')
    expect(bar.findComponent({ name: 'BankTransactionJournalLink' }).exists()).toBe(false)
    expect(bar.find('button[aria-label="bank.show_transaction_text"]').exists()).toBe(false)
    const menu = bar.findComponent(RowActionsMenu)
    expect(menu.props('inlineCount')).toBe(1)
    const list = menu.props('actions') as RowAction[]
    const text = list.find(a => a.key === 'show-text')
    expect(text?.menuOnly).toBe(true)
    expect(list.find(a => a.key === 'open-journal')?.menuOnly).toBe(true)
    wrapper.unmount()
  })

  it('na telefonu u podvojného účetnictví zůstane hlavní akcí zaúčtování a vše ostatní je v „…"', async () => {
    phoneRef.value = true
    const wrapper = mountRow('mobile', notice(false), true)
    await flushPromises()
    const bar = wrapper.get('[data-test="tx-phone-actions"]')
    expect(bar.findComponent({ name: 'PostingRowActions' }).exists()).toBe(true)
    expect(bar.findComponent(RowActionsMenu).props('inlineCount')).toBe(0)
    wrapper.unmount()
  })
})
