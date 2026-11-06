import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  plan: {} as Record<string, unknown>,
  repost: vi.fn(),
  getDocument: vi.fn(),
  saveDocument: vi.fn(),
  previewDocument: vi.fn(),
  toastSuccess: vi.fn(),
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.toastSuccess, error: vi.fn() }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true },
    canEdit: { value: true },
    documentTypes: { value: [{ id: 1, name: 'Projekt', is_active: true, show_on_documents: true }] },
    load: () => Promise.resolve(),
  }),
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    repostPlan: () => Promise.resolve(m.plan),
    listAccounts: () => Promise.resolve([]),
    repost: (...args: unknown[]) => m.repost(...args),
  },
  postingErrorI18nKey: (code: string) => `err.${code}`,
  postingErrorMessage: (_t: unknown, e: any) => `err.${e?.response?.data?.error?.code}`,
}))
vi.mock('@/api/dimensions', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/dimensions')>()),
  dimensionsApi: {
    getDocument: (...args: unknown[]) => m.getDocument(...args),
    saveDocument: (...args: unknown[]) => m.saveDocument(...args),
    previewDocument: (...args: unknown[]) => m.previewDocument(...args),
  },
}))
vi.mock('@/components/ui/Modal.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/accounting/PostingOriginRow.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/accounting/JournalEntryNotes.vue', () => ({ default: { props: ['entryId'], template: '<div class="notes" />' } }))
vi.mock('@/components/dimensions/DimensionChips.vue', () => ({ default: { props: ['dimensions'], template: '<span class="chips" />' } }))
vi.mock('@/components/dimensions/DimensionFields.vue', () => ({
  default: { name: 'DimensionFields', props: ['modelValue', 'disabled', 'teleport'], emits: ['update:modelValue'], template: '<div class="fields" />' },
}))
vi.mock('@/components/accounting/JournalLinesEditor.vue', () => ({
  default: { name: 'JournalLinesEditor', props: ['modelValue', 'accounts', 'listId'], setup: () => ({ valid: true }), template: '<div />' },
}))

import RepostModal from '@/components/accounting/RepostModal.vue'

function plan(strategy: 'replace' | 'reverse' | 'blocked') {
  return {
    strategy, reason_code: strategy === 'blocked' ? 'period_not_open' : null, entry_id: 9, document_no: 'FP-1',
    entry_date: '2026-03-01', target_date: null, date_shifted: false, tax_neutral_available: false,
    period_status: 'closed', locked_until: null, description: 'Popis',
    lines: [{ account_code: '518', side: 'debit', amount: 100 }, { account_code: '321', side: 'credit', amount: 100 }],
  }
}

async function mountModal() {
  const wrapper = mount(RepostModal, { props: { open: true, source: 'purchase-invoices' as const, docId: 42 } })
  await flushPromises()
  return wrapper
}

async function changeDimension(wrapper: Awaited<ReturnType<typeof mountModal>>) {
  wrapper.findComponent({ name: 'DimensionFields' }).vm.$emit('update:modelValue', { 1: 7 })
  await flushPromises()
  await vi.advanceTimersByTimeAsync(500)
  await flushPromises()
}

describe('RepostModal — dimenze dokladu', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    m.repost.mockReset().mockResolvedValue({})
    m.getDocument.mockReset().mockResolvedValue({ header: { 1: 5 }, items: {} })
    m.saveDocument.mockReset().mockResolvedValue({ header: { 1: 7 }, items: {}, restamp: { lines: 2, needs_repost: false, locked: true } })
    m.previewDocument.mockReset().mockResolvedValue({
      header: { 1: 7 }, items: {}, restamp: { lines: 2, needs_repost: false, locked: true }, refused: false,
      lines: [{ id: 1, entry_id: 9, account_code: '518', account_name: 'Služby', side: 'debit', amount: 100, dimensions: { 1: 7 } }],
    })
  })

  it('v uzavřeném období (přeúčtování zablokované) jde uložit samotné dimenze', async () => {
    m.plan = plan('blocked')
    const wrapper = await mountModal()
    expect(m.getDocument).toHaveBeenCalledWith('purchase-invoices', 42)
    expect(wrapper.find('[data-test="repost-save-dimensions"]').exists()).toBe(false)

    await changeDimension(wrapper)
    expect(m.previewDocument).toHaveBeenCalledWith('purchase-invoices', 42, { header: { 1: 7 } })
    expect(wrapper.find('[data-test="repost-dimensions-preview"]').exists()).toBe(true)

    const button = wrapper.get('[data-test="repost-save-dimensions"]')
    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')
    await flushPromises()

    expect(m.saveDocument).toHaveBeenCalledWith('purchase-invoices', 42, { header: { 1: 7 } })
    expect(m.repost).not.toHaveBeenCalled()
    expect(wrapper.emitted('dimensionsSaved')).toHaveLength(1)
    expect(wrapper.emitted('reposted')).toBeUndefined()
  })

  it('odmítnuté rozdělení řádku zablokuje uložení a ukáže důvod', async () => {
    m.plan = plan('blocked')
    m.previewDocument.mockResolvedValue({ header: { 1: 7 }, items: {}, restamp: { lines: 0, needs_repost: true, locked: true }, refused: true, lines: [] })
    const wrapper = await mountModal()
    await changeDimension(wrapper)

    expect(wrapper.find('[data-test="repost-dimensions-refused"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="repost-save-dimensions"]').attributes('disabled')).toBeDefined()
  })

  it('přeúčtování pošle změněné dimenze v téže žádosti', async () => {
    m.plan = plan('replace')
    const wrapper = await mountModal()
    await changeDimension(wrapper)

    const confirm = wrapper.findAll('button').find(b => b.text() === 'accounting.repost.confirm')!
    await confirm.trigger('click')
    await flushPromises()

    expect(m.repost).toHaveBeenCalledWith('purchase-invoices', 42, expect.objectContaining({
      dimensions: { header: { 1: 7 } },
    }))
    expect(m.saveDocument).not.toHaveBeenCalled()
  })

  it('beze změny dimenzí přeúčtování dimenze neposílá', async () => {
    m.plan = plan('replace')
    const wrapper = await mountModal()
    const confirm = wrapper.findAll('button').find(b => b.text() === 'accounting.repost.confirm')!
    await confirm.trigger('click')
    await flushPromises()

    expect(m.repost).toHaveBeenCalledTimes(1)
    expect(m.repost.mock.calls[0][2]).not.toHaveProperty('dimensions')
    expect(m.previewDocument).not.toHaveBeenCalled()
  })
})
