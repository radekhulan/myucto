import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { useBankTransactionActions } from '../useBankTransactionActions'
import BankMatchModal from '@/components/bank/BankMatchModal.vue'
import type { BankTransaction, MatchCandidate } from '@/api/bank'

const mocks = vi.hoisted(() => ({
  bank: { matchCandidates: vi.fn(), splitSuggestions: vi.fn(), matchManual: vi.fn() },
  accounting: { listAccounts: vi.fn(), listSettlements: vi.fn(), createSettlement: vi.fn() },
  reload: vi.fn(), toastInfo: vi.fn(), toast: vi.fn(),
  mode: 'double_entry' as string,
}))
vi.mock('@/api/membershipBank', () => ({ membershipBankApi: { candidates: vi.fn().mockResolvedValue({ candidates: [], next_after_id: null }) } }))
vi.mock('@/api/bank', () => ({ bankApi: mocks.bank }))
vi.mock('@/api/accounting', () => ({ accountingApi: mocks.accounting }))
vi.mock('@/api/cash', () => ({ cashApi: { listRegisters: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: { listGrouped: vi.fn() } }))
vi.mock('@/api/invoices', () => ({ invoicesApi: { searchMatchable: vi.fn() } }))
vi.mock('@/api/gopay', () => ({ gopayApi: { payoutCandidate: vi.fn().mockResolvedValue(null) } }))
vi.mock('@/api/documentRequests', () => ({ documentRequestsApi: {} }))
vi.mock('vue-router', () => ({ useRouter: () => ({}), RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true, hasCommercialFeatures: true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: mocks.mode } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: mocks.toast, error: mocks.toast, info: mocks.toastInfo }) }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => {} }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number, currency = 'CZK') => `${v} ${currency}` }))

const tx = { id: 31, amount: -233.17, currency: 'EUR', posted_at: '2099-06-20', match_status: 'unmatched' } as BankTransaction
const candidate = { type: 'purchase_invoice', id: 5, ref: 'FV-2099-5', amount: 236.84, currency: 'EUR' } as unknown as MatchCandidate

beforeEach(() => {
  vi.clearAllMocks()
  mocks.mode = 'double_entry'
  mocks.bank.matchCandidates.mockResolvedValue({ candidates: [candidate], fallback: false })
  mocks.bank.splitSuggestions.mockResolvedValue({ suggestions: [], window: 7, max: 5 })
  mocks.bank.matchManual.mockResolvedValue({
    matched: true, purchase_invoice_id: 5, partial_payment: true, remaining: 3.67, currency: 'EUR',
  })
  mocks.accounting.listAccounts.mockResolvedValue([
    { id: 11, account_code: '648', name: 'Ostatní provozní výnosy', parent_id: null, is_active: true },
    { id: 12, account_code: '663', name: 'Kurzové zisky', parent_id: null, is_active: true },
  ])
  mocks.accounting.listSettlements.mockResolvedValue({ items: [], default_account: { account_id: null, account_code: '365', account_name: null } })
  mocks.accounting.createSettlement.mockResolvedValue({ id: 1 })
})

describe('nedoplatek přijaté faktury po ručním párování', () => {
  it('nabídne vyrovnání rozdílu předvyplněným zbytkem na 663 u cizí měny', async () => {
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(tx)
    await flushPromises()
    await actions.confirmCandidate(candidate)

    expect(actions.purchaseShortfall.value).toEqual({ purchaseInvoiceId: 5, docNumber: 'FV-2099-5', remaining: 3.67, currency: 'EUR' })
    const wrapper = mount(BankMatchModal, { props: { actions }, global: { stubs: { SearchableSelect: true, MatchSuggestionPanel: true, DateInput: true } } })
    await flushPromises()
    expect(wrapper.text()).toContain('bank.purchase_shortfall.title')
    expect(wrapper.text()).toContain('bank.purchase_shortfall.keep_partial')
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('12')
    expect((wrapper.get('input[type="number"]').element as HTMLInputElement).value).toBe('3.67')

    const settle = wrapper.findAll('button').find(b => b.text().includes('bank.purchase_shortfall.settle'))
    await settle!.trigger('click')
    await flushPromises()
    expect(mocks.accounting.createSettlement).toHaveBeenCalledWith(expect.objectContaining({
      doc_type: 'purchase_invoice', doc_id: 5, amount: 3.67, account_id: 12,
    }))
    expect(actions.purchaseShortfall.value).toBeNull()
    wrapper.unmount()
  })

  it('volba „nechat částečně uhrazené" nic nezaúčtuje', async () => {
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(tx)
    await flushPromises()
    await actions.confirmCandidate(candidate)
    const wrapper = mount(BankMatchModal, { props: { actions }, global: { stubs: { SearchableSelect: true, MatchSuggestionPanel: true, DateInput: true } } })
    await flushPromises()

    const keep = wrapper.findAll('button').find(b => b.text().includes('bank.purchase_shortfall.keep_partial'))
    await keep!.trigger('click')
    expect(actions.purchaseShortfall.value).toBeNull()
    expect(mocks.accounting.createSettlement).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('v daňové evidenci jen oznámí částečnou úhradu', async () => {
    mocks.mode = 'tax_evidence'
    const actions = useBankTransactionActions({ reload: mocks.reload })
    actions.startMatch(tx)
    await flushPromises()
    await actions.confirmCandidate(candidate)

    expect(actions.purchaseShortfall.value).toBeNull()
    expect(mocks.toastInfo).toHaveBeenCalledWith(expect.stringContaining('bank.purchase_shortfall.partial_info'))
  })
})
