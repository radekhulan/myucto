import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, reactive } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { useBankTransactionActions, type BankDetailAction, type BankTransactionActions } from '../useBankTransactionActions'
import BankTransactionDialogs from '@/components/bank/BankTransactionDialogs.vue'
import BankPayoutDialog from '@/components/bank/BankPayoutDialog.vue'
import ActionBar from '@/components/ui/ActionBar.vue'
import type { BankTransaction, MatchSuggestion, SplitSuggestion } from '@/api/bank'

const mocks = vi.hoisted(() => ({
  bank: { matchCandidates: vi.fn(), splitSuggestions: vi.fn(), matchManual: vi.fn(), matchMultiple: vi.fn(),
    matchMultiplePurchases: vi.fn(), matchPayout: vi.fn(), ignore: vi.fn(), unmatch: vi.fn(), unignore: vi.fn(), createPurchaseInvoice: vi.fn(),
    acceptMatchSuggestion: vi.fn(), rejectMatchSuggestion: vi.fn() },
  isClientRole: false, request: vi.fn(), toast: vi.fn(), push: vi.fn(), canWrite: vi.fn(), canRead: vi.fn(), searchOpen: vi.fn(),
  escape: null as null | (() => void),
}))
const supplier = reactive({ currentSupplierId: 1 })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => supplier }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: mocks.canRead, isClientRole: mocks.isClientRole, canWrite: mocks.canWrite }) }))
vi.mock('@/api/membershipBank', () => ({ membershipBankApi: { candidates: vi.fn().mockResolvedValue({ candidates: [], next_after_id: null }) } }))
vi.mock('@/api/bank', () => ({ bankApi: mocks.bank }))
vi.mock('@/api/invoices', () => ({ invoicesApi: { searchOpen: mocks.searchOpen } }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: {} }))
vi.mock('@/api/documentRequests', () => ({ documentRequestsApi: { createFromBankTransaction: mocks.request } }))
vi.mock('@/api/gopay', () => ({ gopayApi: {} }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: mocks.push }), RouterLink: { template: '<a><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: mocks.toast, error: mocks.toast, info: mocks.toast }) }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: (_key: string, callback: () => void) => { mocks.escape = callback } }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/api/errors', async importOriginal => ({ ...await importOriginal<typeof import('@/api/errors')>(), apiErrorMessage: (e: Error) => e.message, apiErrorCode: () => undefined }))

const wrappers: ReturnType<typeof mount>[] = []
function setup(refresh = vi.fn(), fallbackCurrency?: () => string) {
  let actions!: BankTransactionActions
  wrappers.push(mount(defineComponent({ setup() { actions = useBankTransactionActions({ reload: refresh, fallbackCurrency }); return () => null } })))
  return actions
}
function row(status: BankTransaction['match_status'] = 'unmatched') {
  return reactive({ id: 7, amount: -25, posted_at: '2099-01-01', currency: 'CZK', match_status: status, ignore_origin: status === 'ignored' ? 'manual' : null } as BankTransaction)
}
function close(actions: BankTransactionActions, action: BankDetailAction) {
  const handlers = { match: actions.closeMatch, payout: actions.closePayout, create: actions.closeCreate, request: actions.closeRequestDoc, ignore: actions.closeIgnore, unmatch: actions.closeUnmatch }
  handlers[action]()
}
function payoutRow() {
  return Object.assign(row(), { amount: 560, source: 'statement' } as const)
}
beforeEach(() => {
  vi.resetAllMocks()
  supplier.currentSupplierId = 1
  mocks.isClientRole = false
  mocks.canWrite.mockReturnValue(true)
  mocks.canRead.mockReturnValue(true)
  mocks.bank.matchCandidates.mockResolvedValue({ candidates: [], fallback: false })
  mocks.bank.splitSuggestions.mockResolvedValue({ suggestions: [], window: 7 })
})
afterEach(() => { for (const wrapper of wrappers.splice(0)) wrapper.unmount() })

describe('actions opened from bank transaction detail', () => {
  it.each<BankDetailAction>(['match', 'create', 'request', 'ignore', 'unmatch'])('returns to the same detail after cancelling %s', async action => {
    const actions = setup(), tx = row(action === 'unmatch' ? 'ignored' : 'unmatched')
    actions.textDetail.value = tx
    await actions.runDetailAction(action)
    expect(actions.textDetail.value).toBeNull()
    close(actions, action)
    await flushPromises()
    expect(actions.textDetail.value).toBe(tx)
  })

  it('does not open a detail when cancelling an action started from a row', async () => {
    const actions = setup()
    actions.startMatch(row())
    actions.closeMatch()
    await flushPromises()
    expect(actions.textDetail.value).toBeNull()
  })

  it.each<BankDetailAction>(['match', 'create'])('returns on Escape from %s', async action => {
    const actions = setup(), tx = row()
    actions.textDetail.value = tx
    await actions.runDetailAction(action)
    mocks.escape?.()
    await flushPromises()
    expect(actions.textDetail.value).toBe(tx)
  })

  it('guards permissions, transaction state and payroll matching', async () => {
    const actions = setup(), tx = row()
    mocks.canWrite.mockReturnValue(false)
    actions.textDetail.value = tx
    await actions.runDetailAction('match')
    expect(actions.matchingTx.value).toBeNull()
    mocks.canWrite.mockImplementation(permission => permission === 'purchase_invoices.create')
    expect(actions.canRunDetailAction(tx, 'create')).toBe(true)
    expect(actions.canRunDetailAction(tx, 'match')).toBe(false)
    tx.amount = 25
    expect(actions.canRunDetailAction(tx, 'create')).toBe(false)
    mocks.canWrite.mockReturnValue(true)
    tx.match_status = 'manual'
    expect(actions.canRunDetailAction(tx, 'ignore')).toBe(false)
    expect(actions.canRunDetailAction(tx, 'unmatch')).toBe(true)
    tx.posting = { payroll_matched: true } as BankTransaction['posting']
    for (const action of ['match', 'create', 'request', 'ignore', 'unmatch'] as BankDetailAction[]) {
      expect(actions.canRunDetailAction(tx, action)).toBe(false)
    }
  })

  it.each(['manual', 'notice_transfer', 'rule', null] as const)('restores staff ignored movement %s through the original unmatch endpoint', async origin => {
    mocks.canRead.mockReturnValue(false)
    mocks.canWrite.mockImplementation(permission => permission === 'bank.match')
    const actions = setup(), tx = row('ignored')
    tx.ignore_origin = origin
    expect(actions.canRunDetailAction(tx, 'unmatch')).toBe(true)
    actions.unmatchTx(tx)
    await actions.confirmUnmatch()
    expect(mocks.bank.unmatch).toHaveBeenCalledWith(tx.id)
    expect(mocks.bank.unignore).not.toHaveBeenCalled()
  })

  it('lets staff ignore with bank.ignore even without bank.match', () => {
    mocks.canWrite.mockImplementation(permission => permission === 'bank.ignore')
    const actions = setup()
    expect(actions.canRunDetailAction(row(), 'ignore')).toBe(true)
    expect(actions.canRunDetailAction(row(), 'match')).toBe(false)
  })

  it('preserves staff matching without new bank read or document payment prerequisites', async () => {
    mocks.canRead.mockReturnValue(false)
    mocks.canWrite.mockImplementation(permission => permission === 'bank.match')
    const actions = setup(), tx = row()
    expect(actions.canRunDetailAction(tx, 'match')).toBe(true)
    // Ignorování má vlastní oprávnění bank.ignore, stejně jako na backendu.
    expect(actions.canRunDetailAction(tx, 'ignore')).toBe(false)
    actions.startMatch(tx)
    await flushPromises()
    await actions.confirmCandidate({ id: 1, type: 'purchase_invoice' } as never)
    expect(mocks.bank.matchManual).toHaveBeenCalledWith(tx.id, { purchaseInvoiceId: 1 })
  })

  it.each(['invoices', 'purchase_invoices'] as const)('requires client payment permission for matching and removing %s payments', async agenda => {
    mocks.isClientRole = true
    mocks.canWrite.mockImplementation(permission => permission === 'bank.match')
    const actions = setup(), tx = row('manual')
    if (agenda === 'invoices') tx.matched_invoice_id = 123
    else tx.matched_purchase_invoice_id = 123
    expect(actions.canRunDetailAction(tx, 'unmatch')).toBe(false)
    tx.match_status = 'unmatched'
    expect(actions.canRunDetailAction(tx, 'match')).toBe(false)
    mocks.canWrite.mockImplementation(permission => permission === 'bank.match' || permission === (agenda === 'invoices' ? 'invoices.mark_paid' : 'purchase_invoices.transition'))
    expect(actions.canRunDetailAction(tx, 'match')).toBe(true)
    tx.match_status = 'manual'
    expect(actions.canRunDetailAction(tx, 'unmatch')).toBe(true)
    mocks.canRead.mockImplementation(permission => permission !== agenda)
    expect(actions.canRunDetailAction(tx, 'unmatch')).toBe(false)
  })

  it('guards mixed suggestions per candidate and rechecks payment rights at confirmation', async () => {
    mocks.isClientRole = true
    mocks.canWrite.mockImplementation(permission => permission === 'bank.match' || permission === 'invoices.mark_paid')
    const actions = setup()
    const candidateBase = {
      invoice_id: null, invoice_ids: null, purchase_invoice_id: null,
      score: 90, signals: {}, flags: [], fee_amount: null, overpayment_amount: null,
      display: { ref: 'SYNTH-161', party: null, amount: 1000, currency: 'CZK', due_date: null, paid: false },
    }
    const suggestion: MatchSuggestion = {
      id: 101, bank_transaction_id: 7, kind: 'single', reason: 'Synthetic suggestion',
      top_score: 90, margin: null, deterministic_core: false, status: 'pending', created_at: '2099-06-15',
      candidates: [
        { ...candidateBase, type: 'invoice', invoice_id: 1 },
        { ...candidateBase, type: 'purchase_invoice', purchase_invoice_id: 2 },
      ],
    }
    actions.setSuggestions(new Map([[7, suggestion]]))
    expect(actions.canReviewSuggestion(suggestion)).toBe(true)
    expect(actions.canAcceptCandidate(suggestion.candidates[0]!)).toBe(true)
    expect(actions.canAcceptCandidate(suggestion.candidates[1]!)).toBe(false)
    await actions.acceptTxSuggestion(7, 1)
    expect(mocks.bank.acceptMatchSuggestion).not.toHaveBeenCalled()
    await actions.rejectTxSuggestion(7)
    expect(mocks.bank.rejectMatchSuggestion).toHaveBeenCalledWith(101)
    mocks.canRead.mockImplementation(permission => permission !== 'purchase_invoices')
    expect(actions.canReviewSuggestion(suggestion)).toBe(false)
    mocks.canRead.mockReturnValue(true)
    const tx = row()
    tx.amount = 25
    tx.source = 'idoklad'
    actions.startMatch(tx)
    await flushPromises()
    actions.matchVarsymbol.value = '123'
    mocks.canWrite.mockImplementation(permission => permission === 'bank.match')
    await actions.confirmMatch()
    await actions.confirmCandidate({ id: 1, type: 'invoice' } as never)
    await actions.confirmSuggestion({ invoices: [{ id: 1 }] } as SplitSuggestion)
    await actions.acceptTxSuggestion(7, 0)
    expect(mocks.bank.matchManual).not.toHaveBeenCalled()
    expect(mocks.bank.matchMultiple).not.toHaveBeenCalled()
    expect(mocks.bank.acceptMatchSuggestion).not.toHaveBeenCalled()
  })

  it('keeps client ignoring independent of matching and protects system ignores', async () => {
    mocks.isClientRole = true
    mocks.canWrite.mockImplementation(permission => permission === 'bank.ignore')
    const actions = setup(), tx = row()
    expect(actions.canRunDetailAction(tx, 'ignore')).toBe(true)
    expect(actions.canRunDetailAction(tx, 'match')).toBe(false)
    expect(actions.canRunDetailAction(tx, 'create')).toBe(false)
    tx.match_status = 'ignored'
    tx.ignore_origin = 'rule'
    expect(actions.canRunDetailAction(tx, 'unmatch')).toBe(false)
    tx.ignore_origin = 'manual'
    expect(actions.canRunDetailAction(tx, 'unmatch')).toBe(true)
    mocks.bank.unignore.mockResolvedValue({ unignored: true })
    actions.unmatchTx(tx)
    await actions.confirmUnmatch()
    expect(mocks.bank.unignore).toHaveBeenCalledWith(tx.id)
    expect(mocks.bank.unmatch).not.toHaveBeenCalled()
  })

  it.each(['payroll', 'transfer', 'outside_saldo'] as const)('hides client actions for %s with accounting data redacted', resolution => {
    mocks.isClientRole = true
    const actions = setup(), tx = row()
    tx.posting = null
    tx.resolution = resolution
    tx.match_available = false
    tx.ignore_available = false
    expect(actions.canRunDetailAction(tx, 'match')).toBe(false)
    expect(actions.canRunDetailAction(tx, 'ignore')).toBe(false)
  })

  it('renders the detail action bar and dispatches its match action', async () => {
    const actions = setup(), tx = row()
    actions.textDetail.value = tx
    const wrapper = mount(BankTransactionDialogs, { props: { actions }, global: { stubs: {
      Modal: { template: '<section><slot /><slot name="footer" /></section>' },
      ActionBar: { props: ['actions'], template: '<div><template v-for="a in actions" :key="a.key"><button v-if="a.show" :data-action="a.key" @click="a.run">{{ a.label }}</button></template></div>' },
    } } })
    wrappers.push(wrapper)
    expect(wrapper.find('[data-action="create"]').exists()).toBe(true)
    expect(wrapper.find('[data-action="unmatch"]').exists()).toBe(false)
    await wrapper.get('[data-action="match"]').trigger('click')
    await flushPromises()
    expect(actions.matchingTx.value).toBe(tx.id)
    expect(actions.textDetail.value).toBeNull()
  })

  it('blocks cancellation during matching, then consumes the return target on success', async () => {
    const actions = setup(), tx = row()
    let resolve!: (value: object) => void
    mocks.bank.matchManual.mockImplementation(() => new Promise(done => { resolve = done }))
    actions.textDetail.value = tx
    await actions.runDetailAction('match')
    actions.matchVarsymbol.value = '123'
    const pending = actions.confirmMatch()
    actions.closeMatch()
    expect(actions.matchingTx.value).toBe(tx.id)
    resolve({})
    await pending
    actions.startMatch(tx)
    actions.closeMatch()
    await flushPromises()
    expect(actions.textDetail.value).toBeNull()
  })

  it.each(['create', 'request', 'ignore', 'unmatch'] as const)('does not restore detail after successful %s', async action => {
    const actions = setup(), tx = row(action === 'unmatch' ? 'ignored' : 'unmatched')
    mocks.bank.createPurchaseInvoice.mockResolvedValue({ purchase_invoice_id: 12 })
    mocks.bank.ignore.mockResolvedValue({ ignore_note: null })
    actions.textDetail.value = tx
    await actions.runDetailAction(action)
    actions.createVendorId.value = 3
    const confirm = { create: actions.submitCreatePurchase, request: actions.submitRequestDoc, ignore: actions.confirmIgnore, unmatch: actions.confirmUnmatch }
    await confirm[action]()
    close(actions, action)
    await flushPromises()
    expect(actions.textDetail.value).toBeNull()
    actions.ignoreTx(row())
    actions.closeIgnore()
    await flushPromises()
    expect(actions.textDetail.value).toBeNull()
  })

  it('keeps the return target after failure and returns when the user cancels', async () => {
    const actions = setup(), tx = row()
    mocks.bank.ignore.mockRejectedValue(new Error('Test failure'))
    actions.textDetail.value = tx
    await actions.runDetailAction('ignore')
    await actions.confirmIgnore()
    expect(actions.ignoreTarget.value).toBe(tx)
    actions.closeIgnore()
    await flushPromises()
    expect(actions.textDetail.value).toBe(tx)
  })

  it('does not restore another supplier’s detail after a supplier change', async () => {
    const actions = setup()
    actions.textDetail.value = row()
    await actions.runDetailAction('match')
    supplier.currentSupplierId = 2
    await flushPromises()
    actions.closeMatch()
    await flushPromises()
    expect(actions.textDetail.value).toBeNull()
    expect(actions.matchingTx.value).toBeNull()
  })

  it('offers payout matching only for unallocated CZK statement credits and eligible staff', () => {
    const actions = setup(vi.fn(), () => 'EUR')
    const tx = payoutRow()
    expect(actions.canRunDetailAction(tx, 'payout')).toBe(true)
    for (const unavailable of [
      { amount: -560 }, { currency: null }, { currency: 'EUR' }, { source: 'email_notice' },
      { match_status: 'manual' }, { match_status: 'auto_partial' }, { match_available: false },
      { posting: { status: 'posted' } }, { match_reason: 'migration_review' }, { matched_invoice_id: 2 },
      { matched_other_items: [{ amount: 20 }] },
    ] as Array<Partial<BankTransaction>>) {
      expect(actions.canRunDetailAction({ ...tx, ...unavailable }, 'payout')).toBe(false)
    }
    mocks.canWrite.mockImplementation(permission => permission === 'bank.match')
    expect(actions.canRunDetailAction(tx, 'payout')).toBe(false)
    expect(actions.canRunDetailAction(tx, 'match')).toBe(true)
    mocks.canWrite.mockReturnValue(true)
    mocks.isClientRole = true
    expect(actions.canRunDetailAction(tx, 'payout')).toBe(false)
  })

  it('uses the overflow tier, keeps the payout dialog after rejection and refreshes after success', async () => {
    const refresh = vi.fn(), actions = setup(refresh), tx = payoutRow()
    const allocations = [{ invoice_id: 21, amount_cents: 33600 }, { invoice_id: 22, amount_cents: 22400 }]
    actions.textDetail.value = tx
    const wrapper = mount(BankTransactionDialogs, { props: { actions }, global: { stubs: {
      Modal: { template: '<section><slot /><slot name="footer" /></section>' },
      ActionBar: true, BankPayoutDialog: true,
    } } })
    wrappers.push(wrapper)
    const action = wrapper.getComponent(ActionBar).props('actions').find(a => a.key === 'payout')
    expect(action).toMatchObject({ tier: 'overflow', show: true })
    action?.run?.()
    await flushPromises()
    expect(actions.textDetail.value).toBeNull()
    expect(actions.payoutTarget.value).toBe(tx)
    mocks.bank.matchPayout.mockRejectedValueOnce(new Error('Balance changed'))
    await actions.confirmPayout(allocations)
    expect(actions.payoutError.value).toBe('Balance changed')
    expect(refresh).not.toHaveBeenCalled()
    actions.closePayout()
    await flushPromises()
    expect(actions.textDetail.value).toBe(tx)
    await actions.runDetailAction('payout')
    mocks.bank.matchPayout.mockResolvedValueOnce({ matched: true })
    await actions.confirmPayout(allocations)
    expect(mocks.bank.matchPayout).toHaveBeenCalledWith(tx.id, allocations)
    expect(actions.payoutTarget.value).toBeNull()
    expect(actions.textDetail.value).toBeNull()
    expect(refresh).toHaveBeenCalledOnce()
  })

  it('uses full outstanding balances in cents and only confirms an exact total with two documents', async () => {
    const document = { currency: 'CZK', client_id: 1, amount_to_pay: 0.30, issue_date: '2099-01-01', invoice_type: 'invoice' }
    mocks.searchOpen.mockResolvedValue([
      { ...document, id: 1, remaining_amount: 0.10 },
      { ...document, id: 2, client_id: 2, remaining_amount: 0.20 },
      { ...document, id: 3 },
      { ...document, id: 4, currency: 'EUR' },
    ])
    const wrapper = mount(BankPayoutDialog, {
      props: { transaction: { ...payoutRow(), amount: 0.30 }, saving: false, error: '' },
      global: { stubs: { Modal: { template: '<section><slot /><slot name="footer" /></section>' }, SearchableSelect: true } },
    })
    wrappers.push(wrapper)
    const picker = () => wrapper.getComponent({ name: 'SearchableSelect' })
    const optionIds = () => (picker().props('options') as Array<{ value: number }>).map(option => option.value)
    const confirm = () => wrapper.findAll('button').find(button => button.text() === 'bank.payout.confirm')!
    const search = async () => {
      picker().vm.$emit('search', 'Synthetic')
      await flushPromises()
    }
    const select = async (id: number) => {
      picker().vm.$emit('update:modelValue', id)
      await flushPromises()
    }
    await search()
    expect(mocks.searchOpen).toHaveBeenCalledWith('Synthetic', 20, 'CZK')
    expect(optionIds()).toEqual([1, 2, 3])
    await select(3)
    expect(confirm().attributes('disabled')).toBeDefined()
    await wrapper.get('ul button').trigger('click')
    await search()
    await select(1)
    expect(confirm().attributes('disabled')).toBeDefined()
    await search()
    expect(optionIds()).toEqual([2, 3])
    await select(2)
    expect(confirm().attributes('disabled')).toBeUndefined()
    await confirm().trigger('click')
    expect(wrapper.emitted('confirm')).toEqual([[[{ invoice_id: 1, amount_cents: 10 }, { invoice_id: 2, amount_cents: 20 }]]])
  })

  it('pays the last document partially with the rest of the transfer and rejects overpaying a row', async () => {
    const document = { currency: 'CZK', issue_date: '2099-01-01', invoice_type: 'invoice' }
    mocks.searchOpen.mockResolvedValue([
      { ...document, id: 11, client_id: 1, amount_to_pay: 1955.63, remaining_amount: 1955.63 },
      { ...document, id: 12, client_id: 2, amount_to_pay: 34011.77, remaining_amount: 34011.77 },
    ])
    const wrapper = mount(BankPayoutDialog, {
      props: { transaction: { ...payoutRow(), amount: 29165.05 }, saving: false, error: '' },
      global: { stubs: { Modal: { template: '<section><slot /><slot name="footer" /></section>' }, SearchableSelect: true } },
    })
    wrappers.push(wrapper)
    const picker = () => wrapper.getComponent({ name: 'SearchableSelect' })
    const button = (label: string) => wrapper.findAll('button').find(candidate => candidate.text() === label)
    const select = async (id: number) => {
      picker().vm.$emit('search', 'Synthetic')
      await flushPromises()
      picker().vm.$emit('update:modelValue', id)
      await flushPromises()
    }
    await select(11)
    await select(12)
    expect(button('bank.payout.confirm')!.attributes('disabled')).toBeDefined()

    await button('bank.payout.pay_last_partially')!.trigger('click')
    const inputs = wrapper.findAll('ul input')
    expect((inputs[1].element as HTMLInputElement).value).toBe('27209.42')
    expect(button('bank.payout.pay_last_partially')).toBeUndefined()
    expect(button('bank.payout.confirm')!.attributes('disabled')).toBeUndefined()

    await inputs[0].setValue('1955.64')
    await inputs[1].setValue('27209.41')
    expect(wrapper.text()).toContain('bank.payout.invalid_amount')
    expect(button('bank.payout.confirm')!.attributes('disabled')).toBeDefined()

    await inputs[0].setValue('1955.63')
    await inputs[1].setValue('27209.424')
    expect(wrapper.text()).toContain('bank.payout.invalid_amount')
    expect(button('bank.payout.confirm')!.attributes('disabled')).toBeDefined()

    await inputs[0].setValue('1955.60')
    await inputs[1].setValue('27209.45')
    expect(wrapper.text()).toContain('bank.payout.settle_rest')
    expect(button('bank.payout.confirm')!.attributes('disabled')).toBeDefined()

    await inputs[0].setValue('1955.63')
    await inputs[1].setValue('27209.42')
    expect(wrapper.text()).not.toContain('bank.payout.settle_rest')
    await button('bank.payout.confirm')!.trigger('click')
    expect(wrapper.emitted('confirm')).toEqual([[[{ invoice_id: 11, amount_cents: 195563 }, { invoice_id: 12, amount_cents: 2720942 }]]])
  })
})
