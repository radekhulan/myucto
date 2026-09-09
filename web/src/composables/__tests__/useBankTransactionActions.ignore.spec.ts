import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import { mount } from '@vue/test-utils'
import { useBankTransactionActions } from '../useBankTransactionActions'
import BankTransactionDialogs from '@/components/bank/BankTransactionDialogs.vue'
import type { BankTransaction } from '@/api/bank'
const api = vi.hoisted(() => ({ ignore: vi.fn(), unmatch: vi.fn(), toast: vi.fn() }))
vi.mock('@/api/bank', () => ({ bankApi: api }))
vi.mock('vue-router', () => ({ useRouter: () => ({}), RouterLink: { props: ['to'], template: '<a><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: api.toast }) }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => {} }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: Error) => e.message }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => `${v} CZK` }))
vi.mock('@/api/invoices', () => ({ invoicesApi: {} }))
vi.mock('@/api/documentRequests', () => ({ documentRequestsApi: {} }))
vi.mock('@/api/gopay', () => ({ gopayApi: {} }))
function tx(status: 'ignored' | 'unmatched' = 'unmatched') {
  return reactive({ id: 7, posted_at: '2099-01-01', amount: -25, currency: 'CZK', match_status: status,
    counterparty_name: 'Test party', description: 'Full synthetic bank description', ignore_note: status === 'ignored' ? 'Test note' : null,
  } as BankTransaction)
}
beforeEach(() => vi.resetAllMocks())
describe('bank confirmation dialogs', () => {
  it('cancel does not call API; success updates row and requests silent refresh', async () => {
    const refresh = vi.fn(), reload = vi.fn()
    const actions = useBankTransactionActions({ reload, refresh }), row = tx()
    actions.ignoreTx(row)
    actions.closeIgnore()
    expect(api.ignore).not.toHaveBeenCalled()
    actions.ignoreTx(row)
    actions.ignoreNote.value = '  Test note  '
    api.ignore.mockResolvedValue({ ignored: true, ignore_note: 'Test note' })
    await actions.confirmIgnore()
    expect(api.ignore).toHaveBeenCalledWith(7, 'Test note')
    expect(row.match_status).toBe('ignored')
    expect(row.ignore_note).toBe('Test note')
    expect(actions.ignoreTarget.value).toBeNull()
    expect(refresh).toHaveBeenCalledOnce()
    expect(reload).not.toHaveBeenCalled()
  })
  it('keeps note on rejection and blocks duplicate submits', async () => {
    let reject!: (reason: Error) => void
    api.unmatch.mockImplementation(() => new Promise((_, no) => { reject = no }))
    const refresh = vi.fn(), actions = useBankTransactionActions({ reload: vi.fn(), refresh }), row = tx('ignored')
    actions.unmatchTx(row)
    const pending = actions.confirmUnmatch()
    await actions.confirmUnmatch()
    actions.closeUnmatch()
    expect(api.unmatch).toHaveBeenCalledOnce()
    expect(actions.unmatchTarget.value).toBe(row)
    reject(new Error('Closed period'))
    await pending
    expect(row.ignore_note).toBe('Test note')
    expect(row.match_status).toBe('ignored')
    expect(actions.unmatchError.value).toBe('Closed period')
    expect(refresh).not.toHaveBeenCalled()
  })
  it('clears note after unmatch; refresh failure does not offer mutation retry', async () => {
    const actions = useBankTransactionActions({ reload: vi.fn(), refresh: vi.fn().mockRejectedValue(new Error('Refresh failed')) }), row = tx('ignored')
    actions.unmatchTx(row)
    api.unmatch.mockResolvedValue({ unmatched: true })
    await actions.confirmUnmatch()
    expect(row.ignore_note).toBeNull()
    expect(row.match_status).toBe('unmatched')
    expect(actions.unmatchTarget.value).toBeNull()
    expect(actions.unmatchError.value).toBe('')
    expect(api.toast).toHaveBeenCalledWith('Refresh failed')
  })
  it('shows undo ignore label, existing note and complete details', async () => {
    const actions = useBankTransactionActions({ reload: vi.fn() }), row = tx('ignored')
    actions.unmatchTx(row)
    const wrapper = mount(BankTransactionDialogs, { props: { actions, ownAccount: '1000000005', ownBankCode: '0100' },
      global: { stubs: { Modal: { props: ['title'], template: '<section><h1>{{ title }}</h1><slot /><slot name="footer" /></section>' } } },
    })
    expect(wrapper.text()).toContain('bank.unignore')
    expect(wrapper.text()).toContain('Test note')
    expect(wrapper.text()).toContain('bank.unmatch_note_removed')
    actions.closeUnmatch()
    actions.textDetail.value = row
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toContain('Test party')
    expect(wrapper.text()).toContain('1000000005 / 0100')
    expect(wrapper.text()).toContain('Full synthetic bank description')
    wrapper.unmount()
  })
})
