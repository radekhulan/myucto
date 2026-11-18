import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { useBankTransactionActions } from '../useBankTransactionActions'
import BankMatchModal from '@/components/bank/BankMatchModal.vue'
import BankTransactionRow from '@/components/bank/BankTransactionRow.vue'
import BankTransactionDialogs from '@/components/bank/BankTransactionDialogs.vue'
import type { BankTransaction, SplitSuggestion } from '@/api/bank'

const mocks = vi.hoisted(() => ({
  bank: { matchCandidates: vi.fn(), splitSuggestions: vi.fn(), matchMultiple: vi.fn(), matchMultiplePurchases: vi.fn() },
  purchases: vi.fn(), invoices: vi.fn(), reload: vi.fn(), toast: vi.fn(),
}))
vi.mock('@/api/membershipBank', () => ({ membershipBankApi: { candidates: vi.fn().mockResolvedValue({ candidates: [], next_after_id: null }) } }))
vi.mock('@/api/bank', () => ({ bankApi: mocks.bank }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: { listGrouped: mocks.purchases } }))
vi.mock('@/api/invoices', () => ({ invoicesApi: { searchMatchable: mocks.invoices } }))
vi.mock('@/api/gopay', () => ({ gopayApi: { payoutCandidate: vi.fn().mockResolvedValue(null) } }))
vi.mock('@/api/documentRequests', () => ({ documentRequestsApi: {} }))
vi.mock('vue-router', () => ({ useRouter: () => ({}), RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
// Dimenze (Firma → Dimenze) jsou u firmy vypnuté — řádek pohybu je nevykreslí.
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: false }, canEdit: { value: false }, documentTypes: { value: [] },
    valueById: { value: new Map() }, typeById: { value: new Map() },
    load: () => Promise.resolve(), valueLabel: () => '', pathOf: () => [],
  }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: mocks.toast, error: mocks.toast, info: mocks.toast }) }))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: vi.fn() }) }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => {} }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number, currency: string) => `${v} ${currency}` }))

const transaction = (amount: number, currency = 'EUR') => ({ id: 17, amount, currency, posted_at: '2026-01-12', match_status: 'unmatched' } as BankTransaction)
const suggestion = { client_id: 1, client_name: 'Test vendor', currency: 'EUR', total: 75, count: 2,
  invoices: [{ id: 21, ref: 'TEST-A', amount: 25, currency: 'EUR', converted: null, issue_date: '2026-01-10', due_date: null },
    { id: 22, ref: 'TEST-B', amount: 50, currency: 'EUR', converted: null, issue_date: '2026-01-10', due_date: null }] } satisfies SplitSuggestion

beforeEach(() => {
  vi.clearAllMocks()
  mocks.bank.matchCandidates.mockResolvedValue({ candidates: [], fallback: false })
  mocks.bank.splitSuggestions.mockResolvedValue({ suggestions: [suggestion], window: 7, max: 5 })
  mocks.bank.matchMultiple.mockResolvedValue({ matched: true })
  mocks.bank.matchMultiplePurchases.mockResolvedValue({ matched: true })
  mocks.purchases.mockResolvedValue({ data: [{ invoices: [{ id: 21, vendor_invoice_number: 'TEST-A', vendor_company_name: 'Test vendor', amount_to_pay: 25, currency: 'EUR', due_date: '2026-01-20' }] }] })
  mocks.invoices.mockResolvedValue([])
})
afterEach(() => vi.useRealTimers())

describe('sloučené bankovní úhrady', () => {
  it('opožděný návrh nepřepíše nově vybranou kotvu', async () => {
    let resolveOld!: (value: unknown) => void
    mocks.bank.splitSuggestions.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(transaction(-75))
    actions.onAnchorSelect(21)
    await flushPromises()
    resolveOld({ suggestions: [], window: 60, max: 5 })
    await flushPromises()
    expect(actions.splitSuggestions.value).toEqual([suggestion])
    expect(actions.splitWindow.value).toBe(7)
  })

  it('chybu načtení nezamění za prázdnou nabídku bez vysvětlení', async () => {
    mocks.bank.splitSuggestions.mockRejectedValueOnce(new Error('Synthetic request failure'))
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(transaction(-75))
    await flushPromises()
    expect(actions.matchError.value).not.toBe('')
    expect(actions.loadingSplit.value).toBe(false)
  })

  it.each(['EUR', 'CZK'])('nabídne více přijatých faktur pro odchozí %s platbu', async currency => {
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(transaction(-75, currency))
    await flushPromises()
    expect(mocks.bank.splitSuggestions).toHaveBeenCalledWith(17, { window: 7, invoiceId: undefined, purchaseInvoiceId: undefined })
    const wrapper = mount(BankMatchModal, { props: { actions }, global: { stubs: { SearchableSelect: true, MatchSuggestionPanel: true } } })
    expect(wrapper.text()).toContain('bank.split_title')
    await actions.confirmSuggestion(suggestion)
    expect(mocks.bank.matchMultiplePurchases).toHaveBeenCalledWith(17, [21, 22])
    expect(mocks.bank.matchMultiple).not.toHaveBeenCalled()
    expect(mocks.reload).toHaveBeenCalledOnce()
    wrapper.unmount()
  })

  it('kotva odchozí platby hledá přijaté faktury a posílá purchaseInvoiceId', async () => {
    vi.useFakeTimers()
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(transaction(-75))
    actions.onAnchorSearch('TEST')
    await vi.advanceTimersByTimeAsync(250)
    expect(mocks.purchases).toHaveBeenCalledWith(expect.objectContaining({ q: 'TEST', per_page: 20 }))
    expect(mocks.invoices).not.toHaveBeenCalled()
    expect(actions.anchorOptions.value[0]).toEqual({ value: 21, label: 'TEST-A - Test vendor', secondary: '25 EUR · 2026-01-20' })
    actions.onAnchorSelect(21)
    expect(mocks.bank.splitSuggestions).toHaveBeenLastCalledWith(17, { window: 7, invoiceId: undefined, purchaseInvoiceId: 21 })
  })

  it('příchozí platba zachová výběr a potvrzení vydaných faktur', async () => {
    vi.useFakeTimers()
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(transaction(75))
    actions.onAnchorSearch('TEST')
    await vi.advanceTimersByTimeAsync(250)
    expect(mocks.invoices).toHaveBeenCalledWith('TEST', 20)
    expect(mocks.purchases).not.toHaveBeenCalled()
    actions.onAnchorSelect(21)
    expect(mocks.bank.splitSuggestions).toHaveBeenLastCalledWith(17, { window: 7, invoiceId: 21, purchaseInvoiceId: undefined })
    await actions.confirmSuggestion(suggestion)
    expect(mocks.bank.matchMultiple).toHaveBeenCalledWith(17, [21, 22])
    expect(mocks.bank.matchMultiplePurchases).not.toHaveBeenCalled()
  })

  it('ukáže protihodnoty EUR faktur i rozdíl proti CZK platbě', async () => {
    const fx = { ...suggestion, currency: 'CZK', total: 1875,
      invoices: suggestion.invoices.map(invoice => ({ ...invoice, converted: invoice.amount * 25 })) }
    mocks.bank.splitSuggestions.mockResolvedValue({ suggestions: [fx], window: 7, max: 5 })
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(transaction(-1880, 'CZK'))
    await flushPromises()
    const wrapper = mount(BankMatchModal, { props: { actions }, global: { stubs: { SearchableSelect: true, MatchSuggestionPanel: true } } })
    expect(wrapper.text()).toContain('25 EUR')
    expect(wrapper.text()).toContain('625 CZK')
    expect(wrapper.get('[data-testid="split-difference"]').text()).toContain('5 CZK')
    expect(wrapper.text()).toContain('bank.split_fx_hint')
    wrapper.unmount()
  })

  it.each(['desktop', 'mobile'] as const)('zobrazí všechny přijaté faktury v pohybu (%s)', async layout => {
    const actions = useBankTransactionActions({ reload: mocks.reload })
    const tx = { ...transaction(-75), matched_purchase_invoice_id: 21,
      matched_purchase_invoices: suggestion.invoices.map(invoice => ({ purchase_invoice_id: invoice.id, ref: invoice.ref,
        vendor_name: `Test vendor ${invoice.id}`, amount: invoice.amount, currency: invoice.currency })) }
    const wrapper = mount(BankTransactionRow, { props: { tx, actions, layout, isDoubleEntry: false }, global: {
      stubs: { BankTransactionJournalLink: true, RowActionsMenu: true, PostingStatusBadge: true, WhyChip: true, PostingRowActions: true, MatchSuggestionPanel: true },
    } })
    expect(wrapper.findAll('a').map(a => a.attributes('href'))).toEqual(expect.arrayContaining(['/purchase-invoices/21', '/purchase-invoices/22']))
    expect(wrapper.text()).toContain('Test vendor 22')
    wrapper.unmount()
    actions.textDetail.value = tx
    const dialog = mount(BankTransactionDialogs, { props: { actions }, global: {
      stubs: { Modal: { template: '<section><slot /></section>' } },
    } })
    expect(dialog.findAll('a').map(a => a.attributes('href'))).toEqual(expect.arrayContaining(['/purchase-invoices/21', '/purchase-invoices/22']))
    dialog.unmount()
  })
})
