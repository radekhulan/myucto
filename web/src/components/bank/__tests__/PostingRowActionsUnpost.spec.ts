import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { BankTransaction } from '@/api/bank'

const m = vi.hoisted(() => ({ unpost: vi.fn(), success: vi.fn(), error: vi.fn() }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error, warning: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: vi.fn() } }))
vi.mock('@/api/bankPosting', () => ({
  bankPostingApi: { unpost: m.unpost },
  bankPostingErrorMessage: () => 'chyba',
}))

import PostingRowActions from '../PostingRowActions.vue'

const tx = { id: 31, amount: -500, posting: { status: 'posted' } } as unknown as BankTransaction

function mountActions() {
  return mount(PostingRowActions, {
    props: { tx, currency: 'CZK' },
    global: { stubs: { PostTransactionModal: true, RepostModal: true } },
    attachTo: document.body,
  })
}

describe('PostingRowActions: zrušení zaúčtování', () => {
  let confirmSpy: ReturnType<typeof vi.spyOn>
  beforeEach(() => {
    vi.clearAllMocks()
    m.unpost.mockResolvedValue({})
    confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)
  })
  afterEach(() => { document.body.innerHTML = '' })

  it('potvrzuje dialogem aplikace, ne nativním confirm()', async () => {
    const wrapper = mountActions()
    await wrapper.get('button').trigger('click')

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(m.unpost).not.toHaveBeenCalled()
    expect(document.body.querySelector('[data-test="confirm-message"]')?.textContent).toBe('bank.posting.unpost_confirm')

    ;(document.body.querySelector('[data-test="confirm-ok"]') as HTMLElement).click()
    await flushPromises()
    expect(m.unpost).toHaveBeenCalledWith(31)
    expect(wrapper.emitted('changed')).toBeTruthy()
    wrapper.unmount()
  })

  it('Zrušit dialog zavře a storno nespustí', async () => {
    const wrapper = mountActions()
    await wrapper.get('button').trigger('click')
    ;(document.body.querySelector('[data-test="confirm-cancel"]') as HTMLElement).click()
    await flushPromises()

    expect(m.unpost).not.toHaveBeenCalled()
    expect(document.body.querySelector('[data-test="confirm-message"]')).toBeNull()
    wrapper.unmount()
  })
})
