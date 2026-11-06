import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'

const m = vi.hoisted(() => ({
  plan: {} as Record<string, unknown>,
  linesPlan: {} as Record<string, unknown>,
  planForLines: null as unknown as ReturnType<typeof vi.fn<(...args: unknown[]) => Promise<unknown>>>,
  repost: vi.fn(),
  savePending: vi.fn(),
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({ enabled: { value: false }, canEdit: { value: false }, documentTypes: { value: [] }, load: () => Promise.resolve() }),
}))
vi.mock('@/api/accounting', () => {
  m.planForLines = vi.fn<(...args: unknown[]) => Promise<unknown>>(() => Promise.resolve(m.linesPlan))
  return {
    accountingApi: {
      repostPlan: () => Promise.resolve(m.plan),
      repostPlanForLines: (...args: unknown[]) => m.planForLines(...args),
      listAccounts: () => Promise.resolve([]),
      repost: m.repost,
    },
    postingErrorI18nKey: (code: string) => `err.${code}`,
    postingErrorMessage: (_t: unknown, e: any) => `err.${e?.response?.data?.error?.code}`,
  }
})
vi.mock('@/components/ui/Modal.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/accounting/PostingOriginRow.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/accounting/JournalEntryNotes.vue', () => ({
  default: defineComponent({
    setup(_, { expose }) {
      const draft = ref('')
      expose({
        get hasPending() { return draft.value.trim() !== '' },
        async savePending() {
          m.savePending(draft.value.trim())
          draft.value = ''
        },
      })
      return { draft }
    },
    template: '<textarea data-test="journal-note" v-model="draft" />',
  }),
}))
vi.mock('@/components/accounting/JournalLinesEditor.vue', () => ({
  default: { name: 'JournalLinesEditor', props: ['modelValue', 'accounts', 'listId'], setup: () => ({ valid: true }), template: '<div />' },
}))

import RepostModal from '@/components/accounting/RepostModal.vue'

const ORIGINAL = [
  { account_code: '518.100', side: 'debit' as const, amount: 1000 },
  { account_code: '343.100', side: 'debit' as const, amount: 210 },
  { account_code: '321.100', side: 'credit' as const, amount: 1210 },
]

/** Zápis v datu zamčeném podaným DPH: bez řádků server neví, zda půjde přepsat na místě. */
function lockedPlan() {
  return {
    strategy: 'reverse', reason_code: 'date_locked', entry_id: 9, document_no: 'PF-1',
    entry_date: '2026-01-21', target_date: '2026-09-25', date_shifted: true, tax_neutral_available: true,
    needs_reversal: true, period_status: 'open', locked_until: '2026-08-31', description: 'Popis',
    already_reversed: false, lines: ORIGINAL,
  }
}

async function mountModal() {
  const wrapper = mount(RepostModal, { props: { open: true, source: 'purchase-invoices' as const, docId: 43 } })
  await flushPromises()
  return wrapper
}

async function editLines(wrapper: Awaited<ReturnType<typeof mountModal>>, lines: typeof ORIGINAL) {
  await wrapper.findComponent({ name: 'JournalLinesEditor' }).vm.$emit('update:modelValue', lines)
  await vi.advanceTimersByTimeAsync(450)
  await flushPromises()
}

describe('RepostModal — přepis na místě v zamčeném datu', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    m.plan = lockedPlan()
    m.planForLines.mockClear()
    m.repost.mockReset().mockResolvedValue({})
    m.savePending.mockReset()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('bez úprav netvrdí storno a nechce potvrzení posunu data', async () => {
    const wrapper = await mountModal()
    expect(wrapper.find('[data-test="repost-tax-neutral-pending"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('accounting.repost.strategy_reverse')
    expect(wrapper.find('[data-test="repost-confirm-shift"]').exists()).toBe(false)
  })

  it('ukáže všechny členy skupiny, předvyplní stávající zaúčtování a předpis z dokladu jen nabídne', async () => {
    const members = [
      { id: 9, entry_date: '2026-01-21', row_version: 2 },
      { id: 10, entry_date: '2026-01-21', row_version: 1 },
    ]
    m.plan = { ...lockedPlan(), reason_code: 'posting_group', tax_neutral_available: false,
      date_shifted: false, target_date: '2026-01-21', locked_until: null,
      posting_entries: members, lines: [...ORIGINAL, ORIGINAL[1], { account_code: '321.100', side: 'credit', amount: 210 }],
      suggested_lines: ORIGINAL,
    }
    const wrapper = await mountModal()
    expect(wrapper.get('[data-test="repost-posting-group"]').text()).toContain('#9')
    expect(wrapper.get('[data-test="repost-posting-group"]').text()).toContain('#10')
    const editor = wrapper.findComponent({ name: 'JournalLinesEditor' })
    // Potvrzení beze změn nesmí převzaté kontace nahradit návrhem z dokladu.
    expect(editor.props('modelValue')).toHaveLength(5)
    expect(wrapper.find('[data-test="repost-suggestion"]').exists()).toBe(true)
    await wrapper.get('[data-test="repost-use-suggested"]').trigger('click')
    expect(wrapper.findComponent({ name: 'JournalLinesEditor' }).props('modelValue')).toHaveLength(3)
    expect(wrapper.find('[data-test="repost-use-current"]').exists()).toBe(true)
    await wrapper.get('[data-test="repost-submit"]').trigger('click')
    await flushPromises()
    expect(m.repost).toHaveBeenCalledWith('purchase-invoices', 43, expect.objectContaining({
      expected_posting_entries: [{ id: 9, row_version: 2 }, { id: 10, row_version: 1 }],
      lines: expect.arrayContaining([expect.objectContaining({ account_code: '343.100', amount: 210 })]),
    }))
    wrapper.unmount()
  })

  it('u skupiny uloží samotnou poznámku bez přeúčtování, ale explicitní Přeúčtovat stále odešle skupinu', async () => {
    m.plan = { ...lockedPlan(), reason_code: 'posting_group', tax_neutral_available: false,
      date_shifted: false, target_date: '2026-01-21', locked_until: null,
      posting_entries: [{ id: 9, entry_date: '2026-01-21', row_version: 2 }, { id: 10, entry_date: '2026-01-21', row_version: 1 }],
      lines: [...ORIGINAL, ORIGINAL[1], { account_code: '321.100', side: 'credit', amount: 210 }],
      suggested_lines: ORIGINAL,
    }
    const wrapper = await mountModal()
    await wrapper.get('[data-test="repost-notes"]').setValue('Prověřit převzatý předpis')
    await wrapper.get('[data-test="repost-submit"]').trigger('click')
    await flushPromises()

    expect(m.savePending).toHaveBeenCalledExactlyOnceWith('Prověřit převzatý předpis')
    expect(m.repost).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('u skupiny vyžaduje výslovné potvrzení přesunu do jiného období', async () => {
    m.plan = { ...lockedPlan(), reason_code: 'posting_group', tax_neutral_available: false,
      posting_entries: [{ id: 9, entry_date: '2026-01-21', row_version: 1 }, { id: 10, entry_date: '2026-01-21', row_version: 1 }],
    }
    const wrapper = await mountModal()
    expect(wrapper.get('[data-test="repost-submit"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="repost-confirm-shift"] input').setValue(true)
    expect(wrapper.get('[data-test="repost-submit"]').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('změna jen analytiky nákladu se přepíše na místě bez zaškrtávátka', async () => {
    m.linesPlan = { ...lockedPlan(), strategy: 'replace', reason_code: 'tax_neutral_rewrite', target_date: '2026-01-21', date_shifted: false, needs_reversal: false, tax_neutral_violation: null }
    const wrapper = await mountModal()
    await editLines(wrapper, [{ ...ORIGINAL[0], account_code: '518.200' }, ORIGINAL[1], ORIGINAL[2]])

    expect(m.planForLines).toHaveBeenCalledTimes(1)
    expect(m.planForLines.mock.calls[0][2]).toEqual([
      { account_code: '518.200', side: 'debit', amount: 1000 },
      { account_code: '343.100', side: 'debit', amount: 210 },
      { account_code: '321.100', side: 'credit', amount: 1210 },
    ])
    expect(wrapper.find('[data-test="repost-in-place"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('accounting.repost.strategy_replace_in_place')
    expect(wrapper.find('[data-test="repost-confirm-shift"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="repost-submit"]').attributes('disabled')).toBeUndefined()
  })

  it('změna DPH vede na storno s důvodem a teprve pak chce potvrzení', async () => {
    m.linesPlan = { ...lockedPlan(), tax_neutral_violation: 'tax_account_changed' }
    const wrapper = await mountModal()
    await editLines(wrapper, [ORIGINAL[0], { ...ORIGINAL[1], amount: 200 }, { ...ORIGINAL[2], amount: 1200 }])

    expect(wrapper.find('[data-test="repost-violation"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('accounting.repost.reverse_because')
    expect(wrapper.find('[data-test="repost-confirm-shift"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="repost-submit"]').attributes('disabled')).toBeDefined()
  })
})
