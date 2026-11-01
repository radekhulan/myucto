import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import UnmatchedCardPayments from '../UnmatchedCardPayments.vue'
import type { UnmatchedCardPayments as Overview } from '@/api/paymentCards'

const m = vi.hoisted(() => ({
  unmatchedPayments: vi.fn(),
  uploadReceipt: vi.fn(),
  rematch: vi.fn(),
  writeOff: vi.fn(),
  listAccounts: vi.fn(),
  push: vi.fn(),
  matchCandidates: vi.fn(),
  splitSuggestions: vi.fn(),
  matchManual: vi.fn(),
  canMatch: true,
  canReadBank: true,
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}))
vi.mock('@/api/paymentCards', () => ({
  paymentCardsApi: { unmatchedPayments: m.unmatchedPayments, uploadReceipt: m.uploadReceipt, rematch: m.rematch, writeOff: m.writeOff },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: m.listAccounts } }))
vi.mock('@/api/otherItems', () => ({ otherItemsApi: { bankCandidates: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/bank', () => ({ bankApi: { matchCandidates: m.matchCandidates, splitSuggestions: m.splitSuggestions, matchManual: m.matchManual } }))
vi.mock('@/components/ui/Modal.vue', () => ({ default: { props: ['title', 'widthClass'], template: '<div><slot /><slot name="footer" /></div>' } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  canWrite: (permission: string) => permission !== 'bank.match' || m.canMatch,
  canRead: (permission: string) => permission !== 'bank' || m.canReadBank,
}) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m.toast }))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (s: string) => s,
  formatMoney: (amount: number, currency: string) => `${amount.toFixed(2)} ${currency}`,
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: m.push }), RouterLink: { template: '<a><slot /></a>' } }))

/** Syntetická data: dvě karty, jedna v evidenci, druhá neznámá. */
const overview: Overview = {
  from: '2026-06-01',
  to: '2026-06-30',
  count: 2,
  truncated: false,
  groups: [
    {
      key: 'card:1',
      card: { id: 1, label: 'Firemní karta', last4: '1111', holder: 'Jana Testovací', employee_id: null, user_id: null, archived: false },
      last4: '1111',
      holder: 'Jana Testovací',
      count: 1,
      totals: { CZK: 250 },
      transactions: [{
        id: 41, statement_id: 7, posted_at: '2026-06-12', amount: -250, currency: 'CZK', counterparty_name: 'TESTOVACI STANICE', description: null, card_last4: '1111',
        vehicle_hint: { car_id: 5, registration: '1AB 2345', car_name: null, reason: 'ok' },
        clearing_account: '378.101',
      }],
    },
    {
      key: 'last4:2222',
      card: null,
      last4: '2222',
      holder: null,
      count: 1,
      totals: { CZK: 99 },
      transactions: [{ id: 42, statement_id: 7, posted_at: '2026-06-13', amount: -99, currency: 'CZK', counterparty_name: null, description: 'PK: 000000******2222', card_last4: '2222' }],
    },
  ],
}

async function render() {
  const wrapper = mount(UnmatchedCardPayments, { global: { stubs: { EmptyState: true } } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  m.unmatchedPayments.mockResolvedValue(overview)
  m.listAccounts.mockResolvedValue([])
  m.canMatch = true
  m.canReadBank = true
  m.matchCandidates.mockResolvedValue({ candidates: [], fallback: false })
  m.splitSuggestions.mockResolvedValue({ suggestions: [], window: 7 })
})
afterEach(() => { vi.useRealTimers() })

describe('Platby kartou bez dokladu', () => {
  it('vyhledá jiný doklad podle částky a nevrátí zastaralé automatické návrhy', async () => {
    vi.useFakeTimers()
    let resolveInitial!: (value: { candidates: unknown[]; fallback: boolean }) => void
    m.matchCandidates.mockImplementationOnce(() => new Promise(resolve => { resolveInitial = resolve }))
    const wrapper = await render()
    await wrapper.get('[data-testid="manual-match-desktop"]').trigger('click')
    await flushPromises()
    m.matchCandidates.mockResolvedValue({ candidates: [{
      type: 'purchase_invoice', id: 12, ref: 'TEST-SEARCH-12', amount: 1234.50, currency: 'CZK',
      issue_date: '2097-01-01', due_date: '2097-01-15', party: 'Syntetický nalezený dodavatel', paid: false,
    }], fallback: false })
    await wrapper.get('input[type="search"]').setValue('1 234,50')
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    resolveInitial({ candidates: [], fallback: true })
    await flushPromises()
    expect(m.matchCandidates).toHaveBeenLastCalledWith(41, '1 234,50')
    expect(wrapper.text()).toContain('TEST-SEARCH-12')
    expect(wrapper.text()).toContain('bank.candidate_search_results')
    expect(wrapper.text()).not.toContain('bank.candidates_fallback_hint')
    wrapper.unmount()
  })

  it.each(['manual-match-desktop', 'manual-match-mobile'])('otevře manuální párování přímo přes %s a po úspěchu obnoví přehled', async badge => {
    m.matchCandidates.mockResolvedValue({ candidates: [{
      type: 'purchase_invoice', id: 9, ref: 'TEST-PF-9', amount: 250, currency: 'CZK',
      converted_amount: null, converted_currency: null, issue_date: '2026-06-10', due_date: '2026-06-20',
      party: 'Syntetický dodavatel', paid: false,
    }], fallback: false })
    m.matchManual.mockResolvedValue({ matched: true, posting: null })
    const wrapper = await render()
    await wrapper.find(`[data-testid="${badge}"]`).trigger('click')
    await flushPromises()
    expect(m.matchCandidates).toHaveBeenCalledWith(41)
    expect(m.rematch).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('bank.manual_match_title')
    expect(wrapper.text()).toContain('-250.00 CZK')
    m.unmatchedPayments.mockResolvedValue({ ...overview, count: 0, groups: [] })
    await wrapper.findAll('button').find(button => button.text().includes('TEST-PF-9'))!.trigger('click')
    await flushPromises()
    expect(m.matchManual).toHaveBeenCalledWith(41, { purchaseInvoiceId: 9 })
    expect(m.unmatchedPayments).toHaveBeenCalledTimes(2)
    expect(wrapper.find(`[data-testid="${badge}"]`).exists()).toBe(false)
    expect(wrapper.text()).not.toContain('bank.manual_match_title')
    wrapper.unmount()
  })

  it.each(['match', 'read'])('bez práva %s ponechá neinteraktivní stav a nenabídne manuální párování', async permission => {
    m.canMatch = permission !== 'match'
    m.canReadBank = permission !== 'read'
    const wrapper = await render()
    expect(wrapper.text()).toContain('bank.match_status.unmatched')
    expect(wrapper.find('[data-testid="manual-match-desktop"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="manual-match-mobile"]').exists()).toBe(false)
    expect(m.matchCandidates).not.toHaveBeenCalled()
    expect(m.matchManual).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('seskupí platby po držitelích a neznámou kartu nabídne k založení', async () => {
    const wrapper = await render()
    const text = wrapper.text()
    expect(text).toContain('Jana Testovací')
    expect(text).toContain('payment_cards.unmatched.unknown_holder')
    expect(text).toContain('payment_cards.unmatched.add_card')
    expect(m.unmatchedPayments).toHaveBeenCalledOnce()
  })

  it('v Platebních kartách se ptá jen na platební karty, v detailu kreditky jen na její nákupy', async () => {
    await render()
    expect(m.unmatchedPayments).toHaveBeenLastCalledWith(expect.not.objectContaining({ credit_card_account_id: expect.anything() }))

    mount(UnmatchedCardPayments, { props: { creditCardAccountId: 7 }, global: { stubs: { EmptyState: true } } })
    await flushPromises()
    expect(m.unmatchedPayments).toHaveBeenLastCalledWith(expect.objectContaining({ credit_card_account_id: 7 }))
  })

  it('u platby na čerpací stanici ukáže vozidlo držitele karty', async () => {
    const wrapper = await render()
    const hints = wrapper.findAll('[data-testid="vehicle-hint"]')
    expect(hints).toHaveLength(1)
    expect(hints[0].text()).toBe('payment_cards.unmatched.vehicle_hint')
  })

  it('spárování znovu načte přehled, když platba našla doklad', async () => {
    m.rematch.mockResolvedValue({ bank_transaction_id: 41, result: { status: 'auto_exact', purchase_invoice_id: 9 } })
    const wrapper = await render()
    const matchButton = wrapper.findAll('button').find(b => b.text().includes('payment_cards.unmatched.rematch'))
    await matchButton!.trigger('click')
    await flushPromises()
    expect(m.rematch).toHaveBeenCalledWith(41)
    expect(m.toast.success).toHaveBeenCalledWith('payment_cards.unmatched.rematched')
    expect(m.unmatchedPayments).toHaveBeenCalledTimes(2)
  })

  it('platbu na mezičlenu karty jde uzavřít bez dokladu a přehled se načte znovu', async () => {
    m.writeOff.mockResolvedValue({ entry_id: 5, account_code: '548.990' })
    const wrapper = await render()
    expect(wrapper.find('[data-testid="clearing-account"]').exists()).toBe(true)
    // Platba bez mezičlenu (neznámá karta účtovaná postaru) uzavření nenabízí.
    expect(wrapper.findAll('[data-testid="write-off-expense"]')).toHaveLength(1)

    await wrapper.find('[data-testid="write-off-expense"]').trigger('click')
    await flushPromises()
    // Nejdřív dialog, zaúčtuje se až potvrzením — bez volby účtu výchozí z nastavení.
    expect(m.writeOff).not.toHaveBeenCalled()
    await wrapper.find('[data-testid="write-off-confirm"]').trigger('click')
    await flushPromises()

    expect(m.writeOff).toHaveBeenCalledWith(41, 'expense', null)
    expect(m.unmatchedPayments).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="write-off-dialog"]').exists()).toBe(false)
  })

  it('při uzavření jde zvolit jiný účet, nabídka drží jen účty cíle', async () => {
    m.writeOff.mockResolvedValue({ entry_id: 6, account_code: '513.100' })
    const account = (id: number, code: string) => ({ id, supplier_id: 1, account_code: code, name: `Účet ${code}`, account_type: 'expense',
      normal_side: 'debit', is_synthetic: false, parent_id: null, is_active: true, created_at: '2026-01-01' })
    m.listAccounts.mockResolvedValue([account(1, '513.100'), account(2, '548.990'), account(3, '311.100'), account(4, '378.101')])
    const wrapper = await render()
    await wrapper.find('[data-testid="write-off-expense"]').trigger('click')
    await flushPromises()

    const select = wrapper.find('[data-testid="write-off-account"]')
    const codes = select.findAll('option').map(o => o.text())
    expect(codes).toEqual(['payment_cards.unmatched.write_off_account_default', '513.100 - Účet 513.100', '548.990 - Účet 548.990'])
    await select.setValue('1')
    await wrapper.find('[data-testid="write-off-confirm"]').trigger('click')
    await flushPromises()
    expect(m.writeOff).toHaveBeenCalledWith(41, 'expense', 1)
  })

  it('bez AI oznámí uložení účtenky do Příchozích dokladů s odkazem na ně', async () => {
    m.uploadReceipt.mockResolvedValue({ stored: 'incoming', submission_id: 3, duplicate: false, folder: 'Příchozí doklady / 2026 / 09', bank_transaction_id: 41 })
    const wrapper = await render()
    await wrapper.findAll('button').find(b => b.text().includes('payment_cards.unmatched.upload'))!.trigger('click')
    const input = wrapper.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [new File(['%PDF-1.4'], 'uctenka.pdf', { type: 'application/pdf' })], configurable: true })
    await input.trigger('change')
    await flushPromises()

    expect(m.toast.success).toHaveBeenCalledWith('payment_cards.unmatched.stored', expect.objectContaining({ label: 'payment_cards.unmatched.open_incoming' }))
    const action = m.toast.success.mock.calls[0][1] as { handler: () => void }
    action.handler()
    expect(m.push).toHaveBeenCalledWith({ name: 'purchase-invoice-submissions' })
  })

  it('nahraná účtenka jde k vybrané platbě kartou', async () => {
    m.uploadReceipt.mockResolvedValue({ purchase_invoice_id: 9, duplicate: false, marked_as_card: true, bank_transaction_id: 42 })
    const wrapper = await render()
    // Druhá skupina = neznámá karta …2222 (tabulka i mobilní karta nesou stejné tlačítko).
    const unknownCardGroup = wrapper.findAll('section')[1]
    const uploadButton = unknownCardGroup.findAll('button').find(b => b.text().includes('payment_cards.unmatched.upload'))
    await uploadButton!.trigger('click')

    const input = wrapper.find('input[type="file"]')
    const file = new File(['%PDF-1.4'], 'uctenka.pdf', { type: 'application/pdf' })
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await flushPromises()

    expect(m.uploadReceipt).toHaveBeenCalledWith(42, file)
    expect(m.toast.success).toHaveBeenCalledWith('payment_cards.unmatched.uploaded', expect.objectContaining({ label: 'payment_cards.unmatched.open_document' }))
  })
})
