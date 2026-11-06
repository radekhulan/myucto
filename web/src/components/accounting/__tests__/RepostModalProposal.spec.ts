import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({ plan: {} as Record<string, unknown> }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({ enabled: { value: false }, canEdit: { value: false }, documentTypes: { value: [] }, load: () => Promise.resolve() }),
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    repostPlan: () => Promise.resolve(m.plan),
    listAccounts: () => Promise.resolve([]),
    repost: vi.fn(),
    repostPlanForLines: vi.fn(() => Promise.resolve(m.plan)),
  },
  postingErrorI18nKey: (code: string) => `err.${code}`,
  postingErrorMessage: (_t: unknown, e: any) => `err.${e?.response?.data?.error?.code}`,
}))
vi.mock('@/components/ui/Modal.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/accounting/PostingOriginRow.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/accounting/JournalEntryNotes.vue', () => ({ default: { props: ['entryId'], template: '<div class="notes" />' } }))
vi.mock('@/components/accounting/JournalLinesEditor.vue', () => ({
  default: { name: 'JournalLinesEditor', props: ['modelValue', 'accounts', 'listId'], setup: () => ({ valid: true }), template: '<div />' },
}))

import RepostModal from '@/components/accounting/RepostModal.vue'
import { accountingApi } from '@/api/accounting'

function plan(lines: Array<{ account_code: string; side: 'debit' | 'credit'; amount: number }>) {
  return {
    strategy: 'replace', reason_code: null, entry_id: 9, document_no: 'B-1',
    entry_date: '2026-03-01', target_date: null, date_shifted: false, tax_neutral_available: false,
    period_status: 'open', locked_until: null, description: 'Popis', lines,
  }
}

async function mountModal(props: Record<string, unknown>) {
  const wrapper = mount(RepostModal, { props: { open: true, source: 'bank-transactions' as const, docId: 42, ...props } })
  await flushPromises()
  return wrapper
}

function editorCodes(wrapper: Awaited<ReturnType<typeof mountModal>>) {
  return (wrapper.findComponent({ name: 'JournalLinesEditor' }).props('modelValue') as Array<{ account_code: string }>)
    .map(l => l.account_code)
}

afterEach(() => {
  vi.useRealTimers()
})

describe('RepostModal — kontace z nového pravidla', () => {
  beforeEach(() => {
    vi.mocked(accountingApi.repostPlanForLines).mockClear()
    m.plan = plan([
      { account_code: '518', side: 'debit', amount: 299 },
      { account_code: '221.001', side: 'credit', amount: 299 },
    ])
  })

  it('při opravě kontace zachová příznak červeného storna', async () => {
    m.plan.lines = [
      { account_code: '518', side: 'debit', amount: 100, is_red_storno: true },
      { account_code: '321', side: 'credit', amount: 100, is_red_storno: true },
    ]
    const wrapper = await mountModal({})
    const rows = wrapper.findComponent({ name: 'JournalLinesEditor' }).props('modelValue')
    expect(rows.map((row: { is_red_storno?: boolean }) => row.is_red_storno)).toEqual([true, true])
    wrapper.unmount()
  })

  it('odesílá červené storno také při náhledu zamčeného přeúčtování', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    m.plan.strategy = 'reverse'
    m.plan.tax_neutral_available = true
    m.plan.lines = [
      { account_code: '518', side: 'debit', amount: 100, is_red_storno: true },
      { account_code: '321', side: 'credit', amount: 100, is_red_storno: true },
    ]
    const wrapper = await mountModal({ source: 'purchase-invoices' })
    const editor = wrapper.findComponent({ name: 'JournalLinesEditor' })
    editor.vm.$emit('update:modelValue', [
      { account_code: '501', side: 'debit', amount: 100, is_red_storno: true },
      { account_code: '321', side: 'credit', amount: 100, is_red_storno: true },
    ])
    await flushPromises()
    await vi.advanceTimersByTimeAsync(450)
    await flushPromises()
    expect(accountingApi.repostPlanForLines).toHaveBeenCalledWith('purchase-invoices', 42, [
      { account_code: '501', side: 'debit', amount: 100, is_red_storno: true },
      { account_code: '321', side: 'credit', amount: 100, is_red_storno: true },
    ])
    wrapper.unmount()
  })

  it('přepočítá plán také při změně samotného znaménka', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    m.plan.strategy = 'reverse'
    m.plan.tax_neutral_available = true
    const wrapper = await mountModal({})
    const editor = wrapper.findComponent({ name: 'JournalLinesEditor' })
    editor.vm.$emit('update:modelValue', (m.plan.lines as Array<Record<string, unknown>>).map(line => ({ ...line, is_red_storno: true })))
    await flushPromises()
    await vi.advanceTimersByTimeAsync(450)
    await flushPromises()
    expect(accountingApi.repostPlanForLines).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('přepíše jen protiúčet a bankovní analytiku ponechá', async () => {
    const wrapper = await mountModal({ proposedAccounts: { debit: '548', credit: '221' } })
    expect(editorCodes(wrapper)).toEqual(['548', '221.001'])
    expect(wrapper.find('[data-test="repost-proposal-hint"]').exists()).toBe(true)
  })

  it('bez návrhu předvyplní původní kontaci', async () => {
    const wrapper = await mountModal({})
    expect(editorCodes(wrapper)).toEqual(['518', '221.001'])
    expect(wrapper.find('[data-test="repost-proposal-hint"]').exists()).toBe(false)
  })

  it('rozúčtovaný zápis nechá beze změny', async () => {
    m.plan = plan([
      { account_code: '518', side: 'debit', amount: 200 },
      { account_code: '548', side: 'debit', amount: 99 },
      { account_code: '221.001', side: 'credit', amount: 299 },
    ])
    const wrapper = await mountModal({ proposedAccounts: { debit: '501', credit: '221' } })
    expect(editorCodes(wrapper)).toEqual(['518', '548', '221.001'])
  })
})
