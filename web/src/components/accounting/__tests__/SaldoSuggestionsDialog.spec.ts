import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { SaldoSettlementSuggestion } from '@/api/accounting'

const m = vi.hoisted(() => ({
  suggestions: vi.fn(),
  create: vi.fn(),
  createAdvance: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { getSaldoSettlementSuggestions: m.suggestions, createSaldoAllocations: m.create, createSaldoAdvanceDeductions: m.createAdvance },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isCompanyAdminRole: false }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v), formatDate: (v: string) => v }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))
vi.mock('@/components/ui/Modal.vue', () => ({
  __esModule: true,
  default: { name: 'Modal', props: ['title', 'widthClass'], template: '<div><slot /><slot name="footer" /></div>' },
}))

import SaldoSuggestionsDialog from '@/components/accounting/SaldoSuggestionsDialog.vue'

function suggestion(lineId: number, docs: number[]): SaldoSettlementSuggestion {
  return {
    key: `${lineId}:${docs.join(',')}`,
    reason: docs.length > 1 ? 'doc_no_sum' : 'doc_no_amount',
    account: { id: 1, code: '321' },
    partner_name: 'Dodavatel',
    line: { line_id: lineId, entry_id: lineId * 10, entry_date: '2099-07-01', document_no: `ID${lineId}`, description: 'zápočet', account_code: '321.200', free_amount: 100 * docs.length },
    allocations: docs.map(d => ({ doc_type: 'purchase_invoice' as const, doc_id: d, doc_no: `PF-${d}`, remaining: 100, amount: 100 })),
  }
}

describe('SaldoSuggestionsDialog', () => {
  beforeEach(() => {
    m.suggestions.mockReset()
    m.create.mockReset()
    m.createAdvance.mockReset()
    m.toastError.mockReset()
  })

  it('potvrdí označené návrhy jako samostatné skupiny', async () => {
    m.suggestions.mockResolvedValue({ as_of: '2099-12-31', truncated: false, items: [suggestion(1, [11]), suggestion(2, [21, 22]), suggestion(3, [31])] })
    m.create.mockResolvedValue({ created: [{ id: 1 }, { id: 2 }, { id: 3 }], failed: [] })
    const wrapper = mount(SaldoSuggestionsDialog, { props: { periodId: 6, asOf: '2099-12-31', account: 'all' } })
    await flushPromises()

    expect(m.suggestions).toHaveBeenCalledWith({ period_id: 6, as_of: '2099-12-31', account: 'all' })
    const boxes = wrapper.findAll('tbody input[type="checkbox"]')
    expect(boxes).toHaveLength(3)
    await boxes[2].setValue(false)
    await wrapper.find('[data-testid="saldo-suggestions-submit"]').trigger('click')
    await flushPromises()

    expect(m.create).toHaveBeenCalledWith({
      groups: [
        { allocations: [{ line_id: 1, doc_type: 'purchase_invoice', doc_id: 11, amount: 100 }] },
        { allocations: [
          { line_id: 2, doc_type: 'purchase_invoice', doc_id: 21, amount: 100 },
          { line_id: 2, doc_type: 'purchase_invoice', doc_id: 22, amount: 100 },
        ] },
      ],
    }, false)
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('velký výběr pošle po dávkách a index odmítnutého návrhu přepočte na celý výběr', async () => {
    const items = Array.from({ length: 101 }, (_, i) => suggestion(i + 1, [1000 + i]))
    m.suggestions.mockResolvedValue({ as_of: '2099-12-31', truncated: false, items })
    m.create
      .mockResolvedValueOnce({ created: Array.from({ length: 100 }, (_, i) => ({ id: i })), failed: [] })
      .mockResolvedValueOnce({ created: [], failed: [{ index: 0, code: 'document_not_open', message: 'Poslední neprošel.' }] })
    const wrapper = mount(SaldoSuggestionsDialog, { props: { periodId: 6, asOf: '2099-12-31', account: 'all' } })
    await flushPromises()

    await wrapper.find('[data-testid="saldo-suggestions-submit"]').trigger('click')
    await flushPromises()

    expect(m.create).toHaveBeenCalledTimes(2)
    expect(m.create.mock.calls[0][0].groups).toHaveLength(100)
    expect(m.create.mock.calls[1][0].groups).toHaveLength(1)
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('Poslední neprošel.')
    expect(wrapper.text()).toContain('PF-1100')
  })

  it('návrh čerpání zálohy potvrdí přes čerpání, rozpad zápisu zvlášť', async () => {
    const advance: SaldoSettlementSuggestion = {
      type: 'advance', key: 'adv:purchase_invoice:41:51', reason: 'advance_sequence', account: { id: 2, code: '314' }, partner_name: 'Dodavatel energie',
      advance: { doc_type: 'purchase_invoice', doc_id: 41, doc_no: 'ZF-41', remaining: 250 },
      final: { doc_type: 'purchase_invoice', doc_id: 51, doc_no: 'FA-51', posted_on: '2099-02-10', unassigned: 250 },
      amount: 250,
    }
    m.suggestions.mockResolvedValue({ as_of: '2099-12-31', truncated: false, items: [suggestion(1, [11]), advance] })
    m.create.mockResolvedValue({ created: [{ id: 1 }], failed: [] })
    m.createAdvance.mockResolvedValue({ created: [{ id: 9 }], failed: [] })
    const wrapper = mount(SaldoSuggestionsDialog, { props: { periodId: 6, asOf: '2099-12-31', account: 'all' } })
    await flushPromises()

    expect(wrapper.text()).toContain('ZF-41')
    expect(wrapper.text()).toContain('accounting.saldo_settle.reason_advance_sequence')
    await wrapper.find('[data-testid="saldo-suggestions-submit"]').trigger('click')
    await flushPromises()

    expect(m.create).toHaveBeenCalledWith({ groups: [{ allocations: [{ line_id: 1, doc_type: 'purchase_invoice', doc_id: 11, amount: 100 }] }] }, false)
    expect(m.createAdvance).toHaveBeenCalledWith({
      groups: [{ deductions: [{ doc_type: 'purchase_invoice', advance_id: 41, final_id: 51, amount: 250 }] }],
    }, false)
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('odmítnutý návrh nechá v seznamu s důvodem', async () => {
    m.suggestions.mockResolvedValue({ as_of: '2099-12-31', truncated: false, items: [suggestion(1, [11]), suggestion(2, [21])] })
    m.create.mockResolvedValue({ created: [{ id: 1 }], failed: [{ index: 1, code: 'document_not_open', message: 'Doklad PF-21 není otevřený.' }] })
    const wrapper = mount(SaldoSuggestionsDialog, { props: { periodId: 6, asOf: '2099-12-31', account: '321' } })
    await flushPromises()

    await wrapper.find('[data-testid="saldo-suggestions-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('Doklad PF-21 není otevřený.')
    expect(m.toastError).toHaveBeenCalled()
    expect(wrapper.emitted('done')).toHaveLength(1)
  })
})
