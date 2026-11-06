import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { BankTransaction } from '@/api/bank'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'

const bankLinks = vi.hoisted(() => vi.fn())
vi.mock('@/api/propertyPayments', () => ({ propertyPaymentsApi: { bankLinks } }))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: vi.fn() }) }))
const supplier = vi.hoisted(() => ({ entityProfile: 'svj' }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({
  currentSupplierId: 3,
  get currentSupplier() { return { accounting_framework: 'cz_504', entity_profile: supplier.entityProfile } },
}) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({ enabled: { value: false }, canEdit: { value: false }, documentTypes: { value: [] }, load: () => Promise.resolve() }),
}))
vi.mock('vue-router', () => ({ useRouter: () => ({}), RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, values?: Record<string, unknown>) => values ? `${key}:${JSON.stringify(values)}` : key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true, isClientRole: false }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))

import BankTransactionRow from '@/components/bank/BankTransactionRow.vue'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import { resetPropertyBankLinks } from '@/components/bank/usePropertyBankLinks'

function actions(): BankTransactionActions {
  return {
    canRunDetailAction: () => true,
    expandedSuggestions: ref(new Set()), expandedDocs: ref(new Set()), toggleSuggestion: vi.fn(), toggleDocs: vi.fn(),
    suggestionFor: () => null, reviewingSuggestion: ref(false), acceptTxSuggestion: vi.fn(), rejectTxSuggestion: vi.fn(),
    startMatch: vi.fn(), openCreate: vi.fn(), openRequestDoc: vi.fn(), ignoreTx: vi.fn(), unmatchTx: vi.fn(),
    textDetail: ref(null),
  } as unknown as BankTransactionActions
}
const row = (id: number, amount = 1500) => ({
  id, statement_id: 4, source: 'statement', amount, currency: 'CZK', posted_at: '2026-02-01', match_status: 'unmatched',
  variable_symbol: '5', constant_symbol: null, specific_symbol: null, counterparty_account: null, counterparty_bank: null,
  counterparty_name: 'Synthetic owner', description: null, bank_ref: null, matched_invoice_id: null, matched_at: null,
}) as unknown as BankTransaction
const stubs = { BankTransactionJournalLink: true, RowActionsMenu: true, PostingStatusBadge: true, WhyChip: true, PostingRowActions: true, MatchSuggestionPanel: true, LinkedDocumentsPanel: true, DimensionChips: true, DocumentDimensionsPanel: true }
const mountRow = (tx: BankTransaction) => mount(BankTransactionRow, { props: { tx, actions: actions(), layout: 'desktop', isDoubleEntry: false }, global: { stubs } })
const menu = (wrapper: ReturnType<typeof mountRow>) => wrapper.findComponent(RowActionsMenu).props('actions') as RowAction[]

beforeEach(() => { resetPropertyBankLinks(); bankLinks.mockReset(); supplier.entityProfile = 'svj' })

describe('BankTransactionRow — úhrady jednotek SVJ', () => {
  it('nabídne spárování na jednotku a vede na úhradu s předvybraným pohybem', async () => {
    bankLinks.mockResolvedValue({ enabled: true, items: { 41: { proposal: { status: 'identified', unit_id: 15, score: 100 } } } })
    const wrapper = mountRow(row(41))
    await flushPromises()
    expect(bankLinks).toHaveBeenCalledWith(3, [41])
    const action = menu(wrapper).find(a => a.key === 'property-unit')
    expect(action?.show).toBe(true)
    expect(action?.variant).toBe('primary')
    expect(action?.icon).toBe('link')
    expect(action?.to).toEqual({ path: '/property/payments', query: { tab: 'receive', bank_transaction: '41' } })
    expect(wrapper.find('[data-test="tx-property-proposal"]').exists()).toBe(true)
  })

  it('u pohybu použitého úhradou SVJ ukáže odkaz na úhradu místo spárování', async () => {
    bankLinks.mockResolvedValue({ enabled: true, items: { 42: { source: { id: 3, amount_cents: '150000', consumed_cents: '150000' }, workflow_id: 12 } } })
    const wrapper = mountRow(row(42))
    await flushPromises()
    expect(menu(wrapper).find(a => a.key === 'property-unit')?.show).toBe(false)
    expect(menu(wrapper).find(a => a.key === 'property-payment')?.show).toBe(true)
    expect(wrapper.get('[data-test="tx-property-payment"]').text()).toContain('"id":12')
  })

  it('firma bez profilu SVJ se na vazby SVJ vůbec neptá', async () => {
    supplier.entityProfile = 'unknown'
    const wrapper = mountRow(row(71))
    await flushPromises()
    expect(bankLinks).not.toHaveBeenCalled()
    expect(menu(wrapper).find(a => a.key === 'property-unit')?.show).toBe(false)
  })

  it('SVJ, kterému server vazby nepovolí, se zeptá jednou za dávku a pak už ne', async () => {
    bankLinks.mockResolvedValue({ enabled: false, items: {} })
    const first = [mountRow(row(51)), mountRow(row(52))]
    await flushPromises()
    expect(bankLinks).toHaveBeenCalledTimes(1)
    expect(bankLinks).toHaveBeenCalledWith(3, [51, 52])
    const later = mountRow(row(53))
    await flushPromises()
    expect(bankLinks).toHaveBeenCalledTimes(1)
    expect(menu(later).find(a => a.key === 'property-unit')?.show).toBe(false)
    expect(menu(first[0]!).find(a => a.key === 'property-unit')?.show).toBe(false)
  })

  it('odchozí pohyb se na SVJ vůbec neptá', async () => {
    mountRow(row(61, -200))
    await flushPromises()
    expect(bankLinks).not.toHaveBeenCalled()
  })
})
