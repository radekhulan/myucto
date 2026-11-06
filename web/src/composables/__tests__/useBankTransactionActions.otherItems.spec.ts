import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { useBankTransactionActions } from '../useBankTransactionActions'
import BankMatchModal from '@/components/bank/BankMatchModal.vue'
import type { BankTransaction, MatchCandidate } from '@/api/bank'

const mocks = vi.hoisted(() => ({
  bank: { matchCandidates: vi.fn(), splitSuggestions: vi.fn(), matchManual: vi.fn(), matchInvoice: vi.fn() },
  otherItems: { bankCandidates: vi.fn(), matchBankTransaction: vi.fn() },
  reload: vi.fn(), toast: vi.fn(),
  client: false,
}))
vi.mock('@/api/bank', () => ({ bankApi: mocks.bank }))
vi.mock('@/api/otherItems', () => ({ otherItemsApi: mocks.otherItems }))
vi.mock('@/api/accounting', () => ({ accountingApi: {} }))
vi.mock('@/api/cash', () => ({ cashApi: { listRegisters: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: {} }))
vi.mock('@/api/invoices', () => ({ invoicesApi: {} }))
vi.mock('@/api/gopay', () => ({ gopayApi: { payoutCandidate: vi.fn().mockResolvedValue(null) } }))
vi.mock('@/api/documentRequests', () => ({ documentRequestsApi: {} }))
vi.mock('vue-router', () => ({ useRouter: () => ({}), RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isClientRole: mocks.client, canRead: () => true, canWrite: () => true, hasCommercialFeatures: true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1, currentSupplier: { accounting_mode: 'double_entry' } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: mocks.toast, error: mocks.toast, info: mocks.toast }) }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => {} }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number, currency = 'CZK') => `${v} ${currency}` }))

const tx = { id: 41, amount: 1200, currency: 'CZK', posted_at: '2099-01-20', match_status: 'unmatched' } as BankTransaction
const invoice = { type: 'invoice', id: 3, ref: 'FV-2099-3', amount: 1200, currency: 'CZK', issue_date: '2099-01-01', party: 'Syntetický odběratel' } as unknown as MatchCandidate
const other = { type: 'other_item', id: 9, ref: 'OP-2099-1', amount: 1200, currency: 'CZK', issue_date: '2099-01-01', party: 'Syntetický partner', side: 'receivable' } as unknown as MatchCandidate
const stubs = { SearchableSelect: true, MatchSuggestionPanel: true, DateInput: true }

beforeEach(() => {
  vi.clearAllMocks()
  mocks.client = false
  mocks.bank.matchCandidates.mockResolvedValue({ candidates: [invoice], fallback: false })
  mocks.bank.splitSuggestions.mockResolvedValue({ suggestions: [], window: 7, max: 5 })
  mocks.otherItems.bankCandidates.mockResolvedValue([other])
  mocks.otherItems.matchBankTransaction.mockResolvedValue({ id: 9 })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('ostatní pohledávky a závazky v dialogu Spárovat', () => {
  it('u ostatní položky ukáže důvody shody včetně čísla splátky', async () => {
    mocks.otherItems.bankCandidates.mockResolvedValue([{ ...other, match_score: 100,
      match_reasons: ['variable_symbol', 'counterparty_account', 'installment_amount'],
      installment: { id: 5, position: 2, due_on: '2099-01-20', remaining_amount: 1200 } }])
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(tx)
    await flushPromises()
    const wrapper = mount(BankMatchModal, { props: { actions }, global: { stubs } })
    await flushPromises()
    const reasons = wrapper.findAll('[data-testid="other-item-reasons"] span').map(span => span.text())
    expect(reasons).toEqual([
      'bank.other_item_match.variable_symbol:{"number":2}',
      'bank.other_item_match.counterparty_account:{"number":2}',
      'bank.other_item_match.installment_amount:{"number":2}',
    ])
    wrapper.unmount()
  })

  it('nabídne je za fakturami se štítkem podle strany a spáruje přes ostatní položky', async () => {
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(tx)
    await flushPromises()
    expect(mocks.otherItems.bankCandidates).toHaveBeenCalledWith(41, '')
    expect(actions.matchCandidates.value.map(c => c.type)).toEqual(['invoice', 'other_item'])

    const wrapper = mount(BankMatchModal, { props: { actions }, global: { stubs } })
    await flushPromises()
    expect(wrapper.text()).toContain('bank.candidate_other_receivable')
    wrapper.unmount()

    expect(await actions.confirmCandidate(other)).toBe(true)
    expect(mocks.otherItems.matchBankTransaction).toHaveBeenCalledWith(9, 41)
    expect(mocks.bank.matchInvoice).not.toHaveBeenCalled()
    expect(mocks.toast).toHaveBeenCalledWith('bank.other_item_matched:{"ref":"OP-2099-1"}')
    expect(mocks.reload).toHaveBeenCalledOnce()
    expect(actions.matchingTx.value).toBeNull()
  })

  it('chyba ostatních položek neschová kandidáty faktur', async () => {
    mocks.otherItems.bankCandidates.mockRejectedValue(new Error('Forbidden'))
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(tx)
    await flushPromises()
    expect(actions.matchCandidates.value).toEqual([invoice])
    expect(actions.matchError.value).toBe('')
  })

  it('varování o širším okně faktur neukazuje, když našlo jen ostatní položky', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    mocks.bank.matchCandidates.mockResolvedValue({ candidates: [], fallback: true })
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(tx)
    await flushPromises()
    expect(actions.matchCandidates.value).toEqual([other])
    expect(actions.candidatesFallback.value).toBe(false)

    mocks.bank.matchCandidates.mockResolvedValue({ candidates: [invoice], fallback: true })
    actions.matchSearch.value = 'FV'
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()
    expect(actions.candidatesFallback.value).toBe(true)
  })

  it('pohyb přiřazený položce nejde ignorovat a volnou část nabídne jen dalším položkám', async () => {
    const partial = { ...tx, matched_other_items: [{ other_item_id: 9, document_no: 'OP-2099-1', side: 'receivable', partner_name: null, amount: 200 }] } as BankTransaction
    const actions = useBankTransactionActions({ reload: mocks.reload })
    expect(actions.canRunDetailAction(partial, 'ignore')).toBe(false)
    expect(actions.canRunDetailAction(partial, 'match')).toBe(true)
    actions.startMatch(partial)
    await flushPromises()
    expect(mocks.bank.matchCandidates).not.toHaveBeenCalled()
    expect(actions.matchCandidates.value).toEqual([other])

    const full = { ...partial, matched_other_items: [{ ...partial.matched_other_items![0], amount: 1200 }] } as BankTransaction
    expect(actions.canRunDetailAction(full, 'match')).toBe(false)
  })

  it('klientské roli je nenabízí', async () => {
    mocks.client = true
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(tx)
    await flushPromises()
    expect(mocks.otherItems.bankCandidates).not.toHaveBeenCalled()
    expect(await actions.confirmCandidate(other)).toBe(false)
  })
})
