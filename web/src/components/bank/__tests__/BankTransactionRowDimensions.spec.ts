import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { BankTransaction } from '@/api/bank'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'

vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: vi.fn() }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: null }) }))
const m = vi.hoisted(() => ({ enabled: true }))

// Dimenze zapnuté: typ Projekt (1) s hodnotou 5 (Firma → Dimenze).
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { get value() { return m.enabled } },
    canEdit: { value: true },
    documentTypes: { value: [{ id: 1, name: 'Projekt', is_active: true, show_on_documents: true }] },
    load: () => Promise.resolve(),
  }),
}))
vi.mock('vue-router', () => ({ useRouter: () => ({}), RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))

import BankTransactionRow from '@/components/bank/BankTransactionRow.vue'
import DimensionChips from '@/components/dimensions/DimensionChips.vue'
import DocumentDimensionsPanel from '@/components/dimensions/DocumentDimensionsPanel.vue'
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

const tx = (dimensions?: Record<number, number>) => ({
  id: 17, statement_id: 3, amount: -1210, currency: 'CZK', posted_at: '2026-05-05', match_status: 'unmatched',
  variable_symbol: null, constant_symbol: null, specific_symbol: null, counterparty_account: null, counterparty_bank: null,
  counterparty_name: 'Dodavatel', description: null, bank_ref: null, matched_invoice_id: null, matched_at: null,
  dimensions,
}) as unknown as BankTransaction

function mountRow(layout: 'desktop' | 'mobile', row: BankTransaction) {
  return mount(BankTransactionRow, {
    props: { tx: row, actions: actions(), layout, isDoubleEntry: true },
    global: {
      stubs: {
        BankTransactionJournalLink: true, RowActionsMenu: true, PostingStatusBadge: true, WhyChip: true, PostingRowActions: true,
        MatchSuggestionPanel: true, LinkedDocumentsPanel: true, DimensionChips: true, DocumentDimensionsPanel: true,
      },
    },
  })
}

function dimensionAction(wrapper: ReturnType<typeof mountRow>): RowAction | undefined {
  const list = wrapper.findComponent(RowActionsMenu).props('actions') as RowAction[]
  return list.find(a => a.key === 'dimensions')
}

describe('BankTransactionRow — dimenze pohybu', () => {
  beforeEach(() => { m.enabled = true })

  it.each(['desktop', 'mobile'] as const)('ukáže štítky dimenzí přímo v řádku (%s)', async layout => {
    const wrapper = mountRow(layout, tx({ 1: 5 }))
    await flushPromises()
    const chips = wrapper.findComponent(DimensionChips)
    expect(chips.exists()).toBe(true)
    expect(chips.props('dimensions')).toEqual({ 1: 5 })
    expect(wrapper.findComponent(DocumentDimensionsPanel).exists()).toBe(false)
    wrapper.unmount()
  })

  it.each(['desktop', 'mobile'] as const)('akce „Dimenze" otevře editor a uložená hodnota přepíše štítky (%s)', async layout => {
    const wrapper = mountRow(layout, tx())
    await flushPromises()
    expect(wrapper.findComponent(DimensionChips).exists()).toBe(false)

    const action = dimensionAction(wrapper)
    expect(action?.show).not.toBe(false)
    action!.run!()
    await flushPromises()
    const panel = wrapper.findComponent(DocumentDimensionsPanel)
    expect(panel.exists()).toBe(true)
    expect(panel.props()).toMatchObject({ docType: 'bank-transactions', docId: 17 })

    panel.vm.$emit('saved', { 1: 5 })
    await flushPromises()
    expect(wrapper.findComponent(DimensionChips).props('dimensions')).toEqual({ 1: 5 })
    wrapper.unmount()
  })

  it.each(['payroll', 'transfer', 'outside_saldo'] as const)('shows %s resolution after accounting data is redacted', async resolution => {
    const row = tx()
    row.posting = null
    row.resolution = resolution
    row.match_available = false
    row.ignore_available = false
    const wrapper = mountRow('mobile', row)
    await flushPromises()
    expect(wrapper.text()).toContain(`bank.match_status.${resolution}`)
    expect(wrapper.text()).not.toContain('bank.match_status.unmatched')
    wrapper.unmount()
  })

  it.each(['desktop', 'mobile'] as const)('pohyb přiřazený ostatní položce ukáže položku místo „Bez dokladu" (%s)', async layout => {
    const row = tx()
    row.posting = { status: 'posted', outside_saldo: true, journal_entry_id: 9 }
    row.matched_other_items = [{ other_item_id: 4, document_no: 'NAJ00006/2026', side: 'payable', partner_name: 'Syntetický dopravce', amount: 1210 }]
    const wrapper = mountRow(layout, row)
    await flushPromises()
    expect(wrapper.text()).toContain('bank.match_status.other_item')
    expect(wrapper.text()).not.toContain('bank.match_status.outside_saldo')
    expect(wrapper.get('[data-test="tx-other-item"] a').attributes('href')).toBe('/other-items/4')
    expect(wrapper.text()).toContain('NAJ00006/2026')
    wrapper.unmount()
  })

  it('při vypnutých dimenzích nic nenabízí ani neukazuje', async () => {
    m.enabled = false
    const wrapper = mountRow('desktop', tx({ 1: 5 }))
    await flushPromises()
    expect(wrapper.findComponent(DimensionChips).exists()).toBe(false)
    expect(dimensionAction(wrapper)?.show).toBe(false)
    wrapper.unmount()
  })
})
