import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import { useBankTransactionActions } from '../useBankTransactionActions'
import type { BankTransaction } from '@/api/bank'

const fixture = vi.hoisted(() => ({ client: false, match: true, ignore: true, unignore: vi.fn(), unmatch: vi.fn(), toast: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isClientRole: fixture.client, canRead: () => true,
  canWrite: (key: string) => key === 'bank.ignore' ? fixture.ignore : fixture.match }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('@/api/bank', () => ({ bankApi: { unignore: fixture.unignore, unmatch: fixture.unmatch } }))
vi.mock('vue-router', () => ({ useRouter: () => ({}) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: fixture.toast }) }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => {} }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: Error) => e.message }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value, formatMoney: (value: number) => String(value) }))
vi.mock('@/api/invoices', () => ({ invoicesApi: {} }))
vi.mock('@/api/documentRequests', () => ({ documentRequestsApi: {} }))
vi.mock('@/api/gopay', () => ({ gopayApi: {} }))

function notice(source: 'email_notice' | 'idoklad' = 'email_notice') {
  return reactive({ id: 157, source, posted_at: '2099-06-15', amount: 100, currency: 'CZK',
    match_status: 'ignored', ignore_origin: 'superseded', ignore_note: 'Synthetic legacy notice',
    confirmation_state: 'pending', confirmation_reason: 'not_checked', confirmation_record_exists: false,
  } as BankTransaction)
}

beforeEach(() => { vi.resetAllMocks(); fixture.client = false; fixture.match = true; fixture.ignore = true })

describe('undo legacy notice supersession', () => {
  it.each(['email_notice', 'idoklad'] as const)('staff uses unignore for legacy %s without confirmation history', async (source) => {
    fixture.match = false
    const refresh = vi.fn(), actions = useBankTransactionActions({ reload: vi.fn(), refresh }), row = notice(source)
    expect(actions.canRunDetailAction(row, 'unmatch')).toBe(true)
    actions.unmatchTx(row)
    await actions.confirmUnmatch()
    expect(fixture.unignore).toHaveBeenCalledWith(157)
    expect(fixture.unmatch).not.toHaveBeenCalled()
    expect(row.match_status).toBe('unmatched')
    expect(row.ignore_origin).toBeNull()
    expect(row.ignore_note).toBeNull()
    expect(refresh).toHaveBeenCalledOnce()
  })

  it.each(['pending', 'review', 'confirmed'] as const)('existing %s history prevents legacy undo', (state) => {
    const actions = useBankTransactionActions({ reload: vi.fn() }), row = notice()
    row.confirmation_state = state; row.confirmation_record_exists = true
    actions.unmatchTx(row)
    expect(actions.canRunDetailAction(row, 'unmatch')).toBe(false)
    expect(actions.unmatchTarget.value).toBeNull()
  })

  it('does not guess absent history when metadata is missing', () => {
    const actions = useBankTransactionActions({ reload: vi.fn() }), row = notice('idoklad')
    delete row.confirmation_record_exists
    expect(actions.canRunDetailAction(row, 'unmatch')).toBe(false)
  })

  it('clients can undo manual ignore but cannot undo legacy supersession', async () => {
    fixture.client = true
    const actions = useBankTransactionActions({ reload: vi.fn() }), row = notice()
    expect(actions.canRunDetailAction(row, 'unmatch')).toBe(false)
    row.ignore_origin = 'manual'
    actions.unmatchTx(row)
    await actions.confirmUnmatch()
    expect(fixture.unignore).toHaveBeenCalledWith(157)
    expect(fixture.unmatch).not.toHaveBeenCalled()
  })

  it('bank.ignore permission is required for staff undo', () => {
    fixture.ignore = false
    const actions = useBankTransactionActions({ reload: vi.fn() })
    expect(actions.canRunDetailAction(notice(), 'unmatch')).toBe(false)
  })

  it('ordinary staff undo of manual ignore keeps the existing unmatch action', async () => {
    const actions = useBankTransactionActions({ reload: vi.fn() }), row = notice()
    row.ignore_origin = 'manual'
    actions.unmatchTx(row)
    await actions.confirmUnmatch()
    expect(fixture.unmatch).toHaveBeenCalledWith(157)
    expect(fixture.unignore).not.toHaveBeenCalled()
  })

  it('client respects the read-only signal after confirmation metadata was redacted', () => {
    fixture.client = true
    const actions = useBankTransactionActions({ reload: vi.fn() }), row = notice()
    row.ignore_origin = 'manual'
    row.ignore_available = false
    delete row.confirmation_state
    delete row.confirmation_record_exists
    expect(actions.canRunDetailAction(row, 'unmatch')).toBe(false)
  })
})
