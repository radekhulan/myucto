import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  plan: {} as Record<string, unknown>,
  repost: vi.fn(),
  savePending: vi.fn(),
  pending: { value: false },
}))

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
    repost: m.repost,
  },
  postingErrorI18nKey: (code: string) => `err.${code}`,
  postingErrorMessage: (_t: unknown, e: any) => `err.${e?.response?.data?.error?.code}`,
}))
vi.mock('@/components/ui/Modal.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/accounting/PostingOriginRow.vue', () => ({ default: { template: '<div />' } }))
// Editor poznámek: vystavuje „rozepsanou poznámku" a její uložení (defineExpose).
vi.mock('@/components/accounting/JournalEntryNotes.vue', () => ({
  default: {
    props: ['entryId'],
    setup(_: unknown, { expose }: { expose: (e: Record<string, unknown>) => void }) {
      const hasPending = ref(m.pending.value)
      expose({ hasPending, savePending: m.savePending })
      return {}
    },
    template: '<div class="notes" />',
  },
}))
vi.mock('@/components/accounting/JournalLinesEditor.vue', () => ({
  default: { name: 'JournalLinesEditor', props: ['modelValue', 'accounts', 'listId'], setup: () => ({ valid: true }), template: '<div />' },
}))

import RepostModal from '@/components/accounting/RepostModal.vue'

function plan(strategy: 'replace' | 'blocked') {
  return {
    strategy, reason_code: strategy === 'blocked' ? 'period_not_open' : null, entry_id: 9, document_no: 'B-1',
    entry_date: '2025-03-01', target_date: null, date_shifted: false, tax_neutral_available: false,
    period_status: strategy === 'blocked' ? 'closed' : 'open', locked_until: null, description: 'Popis',
    lines: [
      { account_code: '518', side: 'debit', amount: 299 },
      { account_code: '221.001', side: 'credit', amount: 299 },
    ],
  }
}

async function mountModal() {
  const wrapper = mount(RepostModal, { props: { open: true, source: 'bank-transactions' as const, docId: 42 } })
  await flushPromises()
  return wrapper
}

describe('RepostModal — změna jen poznámky', () => {
  beforeEach(() => {
    m.repost.mockReset()
    m.savePending.mockReset().mockResolvedValue(undefined)
  })

  it('v uzavřeném roce uloží rozepsanou poznámku bez přeúčtování', async () => {
    m.plan = plan('blocked')
    m.pending.value = true
    const wrapper = await mountModal()
    const submit = wrapper.get('[data-test="repost-submit"]')
    expect(submit.attributes('disabled')).toBeUndefined()
    expect(submit.text()).toBe('accounting.repost.save_note')
    await submit.trigger('click')
    await flushPromises()
    expect(m.savePending).toHaveBeenCalledOnce()
    expect(m.repost).not.toHaveBeenCalled()
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('bez rozepsané poznámky a beze změny kontace v uzavřeném roce nejde nic', async () => {
    m.plan = plan('blocked')
    m.pending.value = false
    const wrapper = await mountModal()
    const submit = wrapper.get('[data-test="repost-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    expect(submit.text()).toBe('accounting.repost.confirm')
  })
})
