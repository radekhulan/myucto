import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { SaldoAdvanceCandidates, SaldoAdvanceFinal } from '@/api/accounting'

const m = vi.hoisted(() => ({
  candidates: vi.fn(),
  create: vi.fn(),
  remove: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
  admin: false,
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    getSaldoAdvanceCandidates: m.candidates,
    createSaldoAdvanceDeductions: m.create,
    deleteSaldoAdvanceDeduction: m.remove,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true, get isCompanyAdminRole() { return m.admin } }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v), formatDate: (v: string) => v }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))
vi.mock('vue-router', () => ({ RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' } }))
vi.mock('@/components/ui/Modal.vue', () => ({
  __esModule: true,
  default: { name: 'Modal', props: ['title', 'widthClass'], template: '<div><slot /><slot name="footer" /></div>' },
}))

import SaldoAdvanceSettleDialog from '@/components/accounting/SaldoAdvanceSettleDialog.vue'

function final(id: number, unassigned: number, over: Partial<SaldoAdvanceFinal> = {}): SaldoAdvanceFinal {
  return {
    doc_type: 'purchase_invoice', doc_id: id, doc_no: `FA-${id}`, issue_date: '2099-07-10', posted_on: '2099-07-10',
    deductible: unassigned, deducted: 0, linked: false, unassigned, amount_match: false, doc_match: false, after_advance: true, ...over,
  }
}

function candidates(items: SaldoAdvanceFinal[], deductions: SaldoAdvanceCandidates['deductions'] = []): SaldoAdvanceCandidates {
  return {
    mode: 'advance',
    document: {
      doc_type: 'purchase_invoice', doc_id: 7, doc_no: 'ZF-7', partner_id: 3, partner_name: 'Dodavatel', currency_code: 'CZK',
      issue_date: '2099-05-10', remaining: 1000, account: { id: 1, code: '314', name: 'Poskytnuté zálohy' }, supported: true,
    },
    deductions,
    items,
  }
}

const target = (id: number, remaining: number) => ({
  doc_type: 'purchase_invoice' as const, doc_id: id, doc_no: `ZF-${id}`, partner_id: 3, partner_name: 'Dodavatel',
  account_code: '314', currency_code: 'CZK', remaining_czk: remaining,
})

describe('SaldoAdvanceSettleDialog', () => {
  beforeEach(() => {
    m.candidates.mockReset()
    m.create.mockReset()
    m.remove.mockReset()
    m.toastError.mockReset()
    m.admin = false
    m.create.mockResolvedValue({ created: [{ id: 1 }], failed: [] })
  })

  it('jedinou shodu částky předvyplní a potvrzení pošle čerpání zálohy fakturou', async () => {
    m.candidates.mockResolvedValue(candidates([final(11, 1000, { amount_match: true }), final(12, 300)]))
    const wrapper = mount(SaldoAdvanceSettleDialog, { props: { items: [target(7, 1000)], asOf: '2099-12-31' } })
    await flushPromises()

    expect(m.candidates).toHaveBeenCalledWith({ doc_type: 'purchase_invoice', doc_id: 7, account: '314', as_of: '2099-12-31' })
    await wrapper.find('[data-testid="saldo-advance-settle-submit"]').trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith({
      deductions: [{ doc_type: 'purchase_invoice', advance_id: 7, final_id: 11, amount: 1000 }],
    }, false)
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('víc shod částky nepředvyplní a částka nad nepřiřazený odpočet faktury neprojde', async () => {
    m.candidates.mockResolvedValue(candidates([final(11, 1000, { amount_match: true }), final(12, 1000, { amount_match: true }), final(13, 300)]))
    const wrapper = mount(SaldoAdvanceSettleDialog, { props: { items: [target(7, 1000)], asOf: '2099-12-31' } })
    await flushPromises()

    const submit = wrapper.find('[data-testid="saldo-advance-settle-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    await wrapper.findAll('tbody input[type="checkbox"]')[2].setValue(true)
    const amount = wrapper.find('tbody input[type="number"]')
    await amount.setValue(300.01)
    expect(wrapper.text()).toContain('accounting.saldo_settle.advance_amount_over_final')
    expect(submit.attributes('disabled')).toBeDefined()
    await amount.setValue(300)
    expect(submit.attributes('disabled')).toBeUndefined()
  })

  it('u víc záloh rozdělí odpočet jedné faktury mezi zálohy', async () => {
    m.candidates.mockResolvedValue(candidates([final(21, 1000)]))
    const wrapper = mount(SaldoAdvanceSettleDialog, { props: { items: [target(7, 600), target(8, 700)], asOf: '2099-12-31' } })
    await flushPromises()

    await wrapper.find('tbody input[type="radio"]').setValue(true)
    await wrapper.find('[data-testid="saldo-advance-settle-submit"]').trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith({
      deductions: [
        { doc_type: 'purchase_invoice', advance_id: 7, final_id: 21, amount: 600 },
        { doc_type: 'purchase_invoice', advance_id: 8, final_id: 21, amount: 400 },
      ],
    }, false)
  })

  it('zrušit jde jen čerpání zadané v saldokontu, ne převzaté z převodu', async () => {
    m.candidates.mockResolvedValue(candidates([], [
      { id: 5, doc_type: 'purchase_invoice', final_id: 31, final_no: 'FA-31', advance_id: 7, advance_no: 'ZF-7', amount: 200, source: 'saldo', posted_on: '2099-07-10', deletable: true },
      { id: 6, doc_type: 'purchase_invoice', final_id: 32, final_no: 'FA-32', advance_id: 7, advance_no: 'ZF-7', amount: 100, source: 'import', posted_on: '2099-07-12', deletable: false },
    ]))
    m.remove.mockResolvedValue({ deleted: 5 })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(SaldoAdvanceSettleDialog, { props: { items: [target(7, 700)], asOf: '2099-12-31' } })
    await flushPromises()

    const remove = wrapper.findAll('button').filter(b => b.attributes('aria-label') === 'accounting.saldo_settle.advance_delete')
    expect(remove).toHaveLength(1)
    await remove[0].trigger('click')
    await flushPromises()
    expect(m.remove).toHaveBeenCalledWith('purchase_invoice', 5, false)
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('uzavřené období přebije správce po potvrzení', async () => {
    m.admin = true
    m.candidates.mockResolvedValue(candidates([final(11, 1000, { amount_match: true })]))
    m.create
      .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'period_closed', message: 'Uzavřené období' } } } })
      .mockResolvedValueOnce({ created: [{ id: 1 }], failed: [] })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(SaldoAdvanceSettleDialog, { props: { items: [target(7, 1000)], asOf: '2099-12-31' } })
    await flushPromises()

    await wrapper.find('[data-testid="saldo-advance-settle-submit"]').trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledTimes(2)
    expect(m.create.mock.calls[1][1]).toBe(true)
    expect(wrapper.emitted('done')).toHaveLength(1)
  })
})
