import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// Zápočet dobropisu (issue #140): nezapočtený dobropis nabídne zápočet, započtený
// ukáže fakturu a zrušení. Faktura bez dobropisu nevykreslí nic.

const m = vi.hoisted(() => ({ get: vi.fn(), apply: vi.fn(), revert: vi.fn() }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/creditNoteOffsets', () => ({ creditNoteOffsetsApi: { get: m.get, apply: m.apply, revert: m.revert } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'err' }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

import CreditNoteOffsetNotice from '@/components/invoices/CreditNoteOffsetNotice.vue'

const offset = {
  id: 1, doc_type: 'invoice', invoice_id: 10, credit_note_id: 11, amount: 2000,
  offset_on: '2026-06-05', invoice_number: '2026001', credit_note_number: '2026002',
}

function mountNotice(isCreditNote: boolean, docId = 11) {
  return mount(CreditNoteOffsetNotice, {
    props: { docType: 'invoice', docId, isCreditNote, currency: 'CZK', canWrite: true },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

describe('CreditNoteOffsetNotice', () => {
  beforeEach(() => {
    m.get.mockReset()
    m.apply.mockReset()
    m.revert.mockReset()
  })

  it('nezapočtený dobropis nabídne zápočet a po něm emituje changed', async () => {
    m.get.mockResolvedValue({ offsets: [], can_offset: true, reason: null })
    m.apply.mockResolvedValue({ offsets: [offset], can_offset: false, reason: 'already_offset' })
    const w = mountNotice(true)
    await flushPromises()

    await w.find('[data-test="credit-note-offset-apply"]').trigger('click')
    await flushPromises()

    expect(m.apply).toHaveBeenCalledWith('invoice', 11)
    expect(w.emitted('changed')).toHaveLength(1)
    expect(w.find('[data-test="credit-note-offset-revert"]').exists()).toBe(true)
  })

  it('započtený dobropis jde zrušit po potvrzení', async () => {
    m.get.mockResolvedValue({ offsets: [offset], can_offset: false, reason: 'already_offset' })
    m.revert.mockResolvedValue({ offsets: [], can_offset: true, reason: null })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = mountNotice(true)
    await flushPromises()

    await w.find('[data-test="credit-note-offset-revert"]').trigger('click')
    await flushPromises()

    expect(m.revert).toHaveBeenCalledWith('invoice', 11)
    confirm.mockRestore()
  })

  it('faktura bez zápočtu nevykreslí nic', async () => {
    m.get.mockResolvedValue({ offsets: [], can_offset: false, reason: 'not_credit_note' })
    const w = mountNotice(false, 10)
    await flushPromises()

    expect(w.find('[data-test="credit-note-offset"]').exists()).toBe(false)
  })
})
