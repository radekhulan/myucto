import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, reactive } from 'vue'
import { useBankTransactionActions, type BankTransactionActions } from '../useBankTransactionActions'
import BankMatchModal from '@/components/bank/BankMatchModal.vue'
import type { BankTransaction } from '@/api/bank'
import type { MembershipBankCandidate, MembershipBankCandidatePage } from '@/api/membershipBank'

const mocks = vi.hoisted(() => ({
  candidates: vi.fn(), match: vi.fn(), reload: vi.fn(),
  read: true, write: true, post: true, client: false,
}))
vi.mock('@/api/membershipBank', () => ({ membershipBankApi: { candidates: mocks.candidates, match: mocks.match } }))
vi.mock('@/api/bank', () => ({ bankApi: {
  matchCandidates: vi.fn().mockResolvedValue({ candidates: [], fallback: false }),
  splitSuggestions: vi.fn().mockResolvedValue({ suggestions: [], window: 7 }),
} }))
vi.mock('@/api/otherItems', () => ({ otherItemsApi: { bankCandidates: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/accounting', () => ({ accountingApi: {} }))
vi.mock('@/api/cash', () => ({ cashApi: { listRegisters: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/gopay', () => ({ gopayApi: { payoutCandidate: vi.fn().mockResolvedValue(null) } }))
vi.mock('@/api/invoices', () => ({ invoicesApi: {} }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: {} }))
vi.mock('@/api/documentRequests', () => ({ documentRequestsApi: {} }))
vi.mock('vue-router', () => ({ useRouter: () => ({}) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => k, te: (k: string) => k === 'bank.membership_errors.nonprofit_membership_payment_dimensions_differ' }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  isClientRole: mocks.client,
  canRead: (key: string) => key !== 'accounting' || mocks.read,
  canWrite: (key: string) => (key !== 'accounting.journal.post' || mocks.write) && (key !== 'bank.post' || mocks.post),
}) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => supplier }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => {} }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: String, formatMoneyMinor: String, formatDate: String }))

const supplier = reactive({ currentSupplierId: 1, currentSupplier: { accounting_mode: 'double_entry', accounting_framework: 'cz_504', entity_profile: 'nonprofit' } as Record<string, string> })
const tx = { id: 41, amount: 1000, currency: 'CZK', match_status: 'unmatched' } as BankTransaction
const claim = (id: number, amount = 500): MembershipBankCandidate => ({ id, amount, amount_cents: String(amount * 100), currency: 'CZK',
  ref: 'SYN-2026', party: `Synthetic member ${id}`, member_id: id, variable_symbol: '123',
  period_from: '2026-01-01', period_to: '2026-12-31', match_reasons: ['variable_symbol'] })

function harness() {
  let actions!: BankTransactionActions
  const wrapper = mount(defineComponent({ setup() {
    actions = useBankTransactionActions({ reload: mocks.reload })
    return () => null
  } }))
  return { actions, wrapper }
}

beforeEach(() => {
  vi.clearAllMocks()
  mocks.read = mocks.write = mocks.post = true
  mocks.client = false
  supplier.currentSupplierId = 1
  mocks.candidates.mockResolvedValue({ candidates: [claim(1), claim(2)], next_cursor: null })
  mocks.match.mockResolvedValue({})
})

describe('členské příspěvky ve sdíleném bankovním dialogu', () => {
  it('nevybere žádnou shodu a potvrdí až výslovně vybraný přesný součet', async () => {
    const { actions, wrapper } = harness()
    actions.startMatch(tx)
    await flushPromises()
    expect(actions.selectedMembershipClaims.value).toEqual([])
    const modal = mount(BankMatchModal, { props: { actions }, global: { stubs: { SearchableSelect: true, MatchSuggestionPanel: true } } })
    const boxes = modal.findAll('[data-testid="membership-bank-match"] input[type="checkbox"]')
    expect(boxes).toHaveLength(2)
    expect(modal.findAll('[data-testid="membership-match-reasons"]')).toHaveLength(2)
    expect(modal.get('[data-testid="membership-match-reasons"]').text()).toContain('bank.other_item_match.variable_symbol')
    await boxes[0]!.setValue(true)
    expect(actions.membershipSelectionValid.value).toBe(false)
    await actions.confirmMembershipMatch()
    expect(mocks.match).not.toHaveBeenCalled()
    await boxes[1]!.setValue(true)
    expect(actions.membershipSelectionValid.value).toBe(true)
    await actions.confirmMembershipMatch()
    expect(mocks.match).toHaveBeenCalledWith(1, 41, [1, 2])
    expect(mocks.reload).toHaveBeenCalledOnce()
    expect(actions.matchingTx.value).toBeNull()
    modal.unmount(); wrapper.unmount()
  })

  it('stránkuje kandidáty a zachová výslovný výběr při dalším hledání', async () => {
    mocks.candidates.mockResolvedValueOnce({ candidates: [claim(1)], next_cursor: 'vs:1' })
      .mockResolvedValueOnce({ candidates: [claim(2)], next_cursor: null })
    const { actions, wrapper } = harness()
    actions.startMatch(tx)
    await flushPromises()
    actions.toggleMembershipClaim(claim(1))
    await actions.loadMembershipCandidates(true)
    expect(mocks.candidates).toHaveBeenLastCalledWith(1, 41, '', 'vs:1')
    expect(actions.membershipCandidates.value.map(c => c.id)).toEqual([1, 2])
    expect(actions.selectedMembershipClaims.value.map(c => c.id)).toEqual([1])
    wrapper.unmount()
  })

  it('přeskočí stránku bez otevřených předpisů a prázdný stav hlásí jen bez pokračování', async () => {
    mocks.candidates.mockResolvedValueOnce({ candidates: [], next_cursor: 'vs:90' })
      .mockResolvedValueOnce({ candidates: [], next_cursor: 'amount:60' })
      .mockResolvedValueOnce({ candidates: [claim(3)], next_cursor: 'amount:3' })
    const { actions, wrapper } = harness()
    actions.startMatch(tx)
    await flushPromises()
    expect(mocks.candidates.mock.calls.map(c => c[3])).toEqual(['0', 'vs:90', 'amount:60'])
    expect(actions.membershipCandidates.value.map(c => c.id)).toEqual([3])
    expect(actions.membershipNextCursor.value).toBe('amount:3')
    mocks.candidates.mockResolvedValue({ candidates: [], next_cursor: 'amount:1' })
    await actions.loadMembershipCandidates()
    expect(actions.membershipCandidates.value).toEqual([])
    expect(actions.membershipNextCursor.value).toBe('amount:1')
    const modal = mount(BankMatchModal, { props: { actions }, global: { stubs: { SearchableSelect: true, MatchSuggestionPanel: true } } })
    const section = modal.get('[data-testid="membership-bank-match"]').text()
    expect(section).not.toContain('bank.membership_candidates_empty')
    expect(section).toContain('bank.membership_more')
    modal.unmount(); wrapper.unmount()
  })

  it('nezpůsobilý pohyb hlásí neutrálně bez chyby a opakování', async () => {
    mocks.candidates.mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'nonprofit_membership_payment_unavailable', message: 'x' } } } })
    const { actions, wrapper } = harness()
    actions.startMatch(tx)
    await flushPromises()
    expect(actions.membershipCandidateError.value).toBe('')
    expect(actions.membershipUnavailable.value).toBe(true)
    const modal = mount(BankMatchModal, { props: { actions }, global: { stubs: { SearchableSelect: true, MatchSuggestionPanel: true } } })
    const section = modal.get('[data-testid="membership-bank-match"]')
    expect(section.find('[role="alert"]').exists()).toBe(false)
    expect(section.text()).toContain('bank.membership_unavailable')
    expect(section.text()).not.toContain('bank.membership_retry')
    expect(section.text()).not.toContain('bank.membership_candidates_empty')
    modal.unmount(); wrapper.unmount()
  })

  it('chybu potvrzení hlásí přeloženým textem podle kódu, neznámý kód textem serveru', async () => {
    const { actions, wrapper } = harness()
    actions.startMatch(tx)
    await flushPromises()
    actions.toggleMembershipClaim(claim(1)); actions.toggleMembershipClaim(claim(2))
    mocks.match.mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'nonprofit_membership_payment_dimensions_differ', message: 'Česká hláška serveru' } } } })
    await actions.confirmMembershipMatch()
    expect(actions.matchError.value).toBe('bank.membership_errors.nonprofit_membership_payment_dimensions_differ')
    mocks.match.mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'unknown_code', message: 'Text serveru' } } } })
    await actions.confirmMembershipMatch()
    expect(actions.matchError.value).toBe('Text serveru')
    wrapper.unmount()
  })

  it('po změně firmy zahodí výběr i pozdní odpověď', async () => {
    let resolve!: (page: MembershipBankCandidatePage) => void
    mocks.candidates.mockReturnValue(new Promise<MembershipBankCandidatePage>(r => { resolve = r }))
    const { actions, wrapper } = harness()
    actions.startMatch(tx)
    actions.toggleMembershipClaim(claim(1))
    supplier.currentSupplierId = 2
    await flushPromises()
    resolve({ candidates: [claim(2)], next_cursor: null })
    await flushPromises()
    expect(actions.membershipCandidates.value).toEqual([])
    expect(actions.selectedMembershipClaims.value).toEqual([])
    expect(actions.matchingTx.value).toBeNull()
    expect(mocks.match).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('bez účetního zápisového práva dovolí čtení, ale odmítne výběr i potvrzení', async () => {
    mocks.write = false
    const { actions, wrapper } = harness()
    actions.startMatch(tx)
    await flushPromises()
    expect(actions.membershipCandidates.value).toHaveLength(2)
    actions.toggleMembershipClaim(claim(1, 1000))
    await actions.confirmMembershipMatch()
    expect(actions.selectedMembershipClaims.value).toEqual([])
    expect(mocks.match).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('nezaúčtovaný pohyb bez práva zaúčtovat banku nevybere, zaúčtovaný ano', async () => {
    mocks.post = false
    const { actions, wrapper } = harness()
    actions.startMatch(tx)
    await flushPromises()
    actions.toggleMembershipClaim(claim(1, 1000))
    expect(actions.selectedMembershipClaims.value).toEqual([])
    actions.startMatch({ ...tx, posting: { status: 'posted' } } as BankTransaction)
    await flushPromises()
    actions.toggleMembershipClaim(claim(1, 1000))
    expect(actions.selectedMembershipClaims.value.map(c => c.id)).toEqual([1])
    wrapper.unmount()
  })

  it('po přepnutí firmy tam a zpět staré potvrzení nezasáhne nový dialog stejného pohybu', async () => {
    let resolve!: (result: object) => void
    mocks.match.mockReturnValue(new Promise<object>(r => { resolve = r }))
    const { actions, wrapper } = harness()
    actions.startMatch(tx)
    await flushPromises()
    actions.toggleMembershipClaim(claim(1, 1000))
    const pending = actions.confirmMembershipMatch()
    supplier.currentSupplierId = 2
    await flushPromises()
    supplier.currentSupplierId = 1
    await flushPromises()
    actions.startMatch(tx)
    await flushPromises()
    expect(actions.matchBusy.value).toBe(false)
    resolve({})
    await pending
    expect(actions.matchingTx.value).toBe(41)
    expect(mocks.reload).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('běžné s.r.o. mimo rámec NNO členskou sekci neukáže ani kandidáty nenačte', async () => {
    supplier.currentSupplier = { accounting_mode: 'double_entry', accounting_framework: 'cz_500', entity_profile: 'company' }
    try {
      const { actions, wrapper } = harness()
      actions.startMatch(tx)
      await flushPromises()
      expect(mocks.candidates).not.toHaveBeenCalled()
      const modal = mount(BankMatchModal, { props: { actions }, global: { stubs: { SearchableSelect: true, MatchSuggestionPanel: true } } })
      expect(modal.find('[data-testid="membership-bank-match"]').exists()).toBe(false)
      modal.unmount()
      wrapper.unmount()
    } finally {
      supplier.currentSupplier = { accounting_mode: 'double_entry', accounting_framework: 'cz_504', entity_profile: 'nonprofit' }
    }
  })

  it('klientské roli členské kandidáty nenačítá', async () => {
    mocks.client = true
    const { actions, wrapper } = harness()
    actions.startMatch(tx)
    await flushPromises()
    expect(mocks.candidates).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})

describe('chybové kódy členského párování', () => {
  it('mají překlad v obou jazycích, aby anglické rozhraní nehlásilo česky', () => {
    // Adaptér předpisu volá párování uvnitř své transakce, jeho chyby dojdou do stejného dialogu.
    const sources = ['Service/Nonprofit/Membership/MembershipBankMatchService.php', 'Service/Nonprofit/Membership/MembershipReceivablePaymentAdapter.php',
      'Action/Nonprofit/MembershipBankMatchAction.php']
      .map(file => readFileSync(resolve(process.cwd(), '../api/src', file), 'utf8')).join('\n')
    const codes = [...new Set([...sources.matchAll(/MembershipException\('([a-z_]+)'/g)].map(m => m[1]))]
    expect(codes).toContain('posting_forbidden')
    expect(codes).toContain('nonprofit_membership_claim_not_found')
    // Účetní chyby zápisu úhrady (uzavřené období, zmrazené dimenze, zamčené datum, povinná dimenze) mají vlastní text.
    codes.push('period_not_open', 'date_locked', 'dimension_required')
    for (const locale of ['cs', 'en']) {
      const messages = JSON.parse(readFileSync(resolve(process.cwd(), `src/i18n/${locale}.json`), 'utf8'))
      const missing = codes.filter(code => typeof messages.bank.membership_errors[code] !== 'string')
      expect(missing, locale).toEqual([])
    }
  })
})
