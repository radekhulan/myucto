import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import UnmatchedCardPayments from '../UnmatchedCardPayments.vue'
import type { UnmatchedCardPayments as Overview } from '@/api/paymentCards'

const m = vi.hoisted(() => ({
  unmatchedPayments: vi.fn(),
  uploadReceipt: vi.fn(),
  rematch: vi.fn(),
  writeOff: vi.fn(),
  push: vi.fn(),
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}))
vi.mock('@/api/paymentCards', () => ({
  paymentCardsApi: { unmatchedPayments: m.unmatchedPayments, uploadReceipt: m.uploadReceipt, rematch: m.rematch, writeOff: m.writeOff },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m.toast }))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (s: string) => s,
  formatMoney: (amount: number, currency: string) => `${amount.toFixed(2)} ${currency}`,
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
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
})

describe('Platby kartou bez dokladu', () => {
  it('seskupí platby po držitelích a neznámou kartu nabídne k založení', async () => {
    const wrapper = await render()
    const text = wrapper.text()
    expect(text).toContain('Jana Testovací')
    expect(text).toContain('payment_cards.unmatched.unknown_holder')
    expect(text).toContain('payment_cards.unmatched.add_card')
    expect(m.unmatchedPayments).toHaveBeenCalledOnce()
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
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = await render()
    expect(wrapper.find('[data-testid="clearing-account"]').exists()).toBe(true)
    // Platba bez mezičlenu (neznámá karta účtovaná postaru) uzavření nenabízí.
    expect(wrapper.findAll('[data-testid="write-off-expense"]')).toHaveLength(1)

    await wrapper.find('[data-testid="write-off-expense"]').trigger('click')
    await flushPromises()

    expect(m.writeOff).toHaveBeenCalledWith(41, 'expense')
    expect(m.unmatchedPayments).toHaveBeenCalledTimes(2)
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
