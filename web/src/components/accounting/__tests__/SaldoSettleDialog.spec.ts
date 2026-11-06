import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { SaldoSettlementCandidates, SaldoSettlementLine } from '@/api/accounting'

const m = vi.hoisted(() => ({
  candidates: vi.fn(),
  create: vi.fn(),
  remove: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
  admin: false,
  offsets: true,
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    getSaldoSettlementCandidates: m.candidates,
    createSaldoAllocations: m.create,
    deleteSaldoAllocation: m.remove,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: (p: string) => p === 'accounting.offsets' ? m.offsets : true, get isCompanyAdminRole() { return m.admin } }),
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
vi.mock('@/components/invoices/PaymentMethodModal.vue', () => ({
  __esModule: true,
  default: { name: 'PaymentMethodModal', props: ['docType', 'docId', 'amount'], template: '<div />' },
}))

import SaldoSettleDialog from '@/components/accounting/SaldoSettleDialog.vue'

function line(id: number, free: number, over: Partial<SaldoSettlementLine> = {}): SaldoSettlementLine {
  return {
    line_id: id, entry_id: id * 10, line_no: 1, account_id: 5, account_code: '321.200', side: 'debit',
    entry_date: '2099-07-01', document_no: `ID${id}`, description: 'zápočet', line_amount: free, free_amount: free,
    links: [], amount_match: false, doc_match: false, partner_match: false, ...over,
  }
}

function candidates(items: SaldoSettlementLine[]): SaldoSettlementCandidates {
  return {
    document: {
      doc_type: 'purchase_invoice', doc_id: 7, doc_no: 'PF-7', partner_id: 3, partner_name: 'Dodavatel',
      currency_code: 'CZK', remaining: 1000, account: { id: 1, code: '321', name: 'Dodavatelé' }, side: 'debit', supported: true,
    },
    allocations: [],
    items,
  }
}

const target = (id: number, remaining: number) => ({
  doc_type: 'purchase_invoice' as const, doc_id: id, doc_no: `PF-${id}`, partner_id: 3, partner_name: 'Dodavatel',
  account_code: '321', currency_code: 'CZK', remaining_czk: remaining,
})

describe('SaldoSettleDialog', () => {
  beforeEach(() => {
    m.candidates.mockReset()
    m.create.mockReset()
    m.toastError.mockReset()
    m.admin = false
    m.offsets = true
    m.create.mockResolvedValue({ created: [{ id: 1 }], failed: [] })
  })

  it('cesta zápočtu nabídne vzájemný zápočet s partnerem jen s právem k zápočtům', async () => {
    m.candidates.mockResolvedValue(candidates([]))
    const wrapper = mount(SaldoSettleDialog, { props: { items: [target(7, 640)], asOf: '2099-12-31' } })
    await flushPromises()
    await wrapper.findAll('[role="tab"]')[1].trigger('click')
    const link = wrapper.findAllComponents({ name: 'RouterLink' }).find(l => (l.props('to') as { name?: string })?.name === 'accounting-offsets')
    expect(link?.props('to')).toEqual({ name: 'accounting-offsets', query: { partner_id: '3', docs: 'purchase_invoice:7:640.00' } })

    m.offsets = false
    const denied = mount(SaldoSettleDialog, { props: { items: [target(7, 640)], asOf: '2099-12-31' } })
    await flushPromises()
    await denied.findAll('[role="tab"]')[1].trigger('click')
    expect(denied.find('[data-testid="saldo-settle-mutual-offset"]').exists()).toBe(false)
  })

  it('zápočet proti účtu schová dialog vyrovnání, aby okno zápočtu nebylo pod ním', async () => {
    m.candidates.mockResolvedValue(candidates([]))
    const wrapper = mount(SaldoSettleDialog, { props: { items: [target(7, 640)], asOf: '2099-12-31' } })
    await flushPromises()
    await wrapper.findAll('[role="tab"]')[1].trigger('click')
    await wrapper.findAll('button').find(b => b.text().includes('accounting.saldo_settle.offset_open'))!.trigger('click')
    await flushPromises()
    expect(wrapper.findComponent({ name: 'PaymentMethodModal' }).exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'Modal' }).exists()).toBe(false)

    wrapper.findComponent({ name: 'PaymentMethodModal' }).vm.$emit('close')
    await flushPromises()
    expect(wrapper.findComponent({ name: 'PaymentMethodModal' }).exists()).toBe(false)
    expect(wrapper.find('[data-testid="saldo-settle-dialog"]').exists()).toBe(true)
  })

  it('jistou shodu předvyplní a potvrzení pošle rozpad jednoho zápisu', async () => {
    m.candidates.mockResolvedValue(candidates([line(1, 1000, { amount_match: true, doc_match: true }), line(2, 50)]))
    const wrapper = mount(SaldoSettleDialog, { props: { items: [target(7, 1000)], asOf: '2099-12-31' } })
    await flushPromises()

    expect(m.candidates).toHaveBeenCalledWith({ doc_type: 'purchase_invoice', doc_id: 7, account: '321', as_of: '2099-12-31' })
    await wrapper.find('[data-testid="saldo-settle-submit"]').trigger('click')
    await flushPromises()

    expect(m.create).toHaveBeenCalledWith({ allocations: [{ line_id: 1, doc_type: 'purchase_invoice', doc_id: 7, amount: 1000 }] }, false)
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('víc zápisů na jeden doklad: částka nad zbytek dokladu se nepotvrdí', async () => {
    m.candidates.mockResolvedValue(candidates([line(1, 800), line(2, 800)]))
    const wrapper = mount(SaldoSettleDialog, { props: { items: [target(7, 1000)], asOf: '2099-12-31' } })
    await flushPromises()

    const boxes = wrapper.findAll('tbody input[type="checkbox"]')
    await boxes[0].setValue(true)
    await boxes[1].setValue(true)
    const amounts = wrapper.findAll('tbody input[type="number"]')
    expect((amounts[1].element as HTMLInputElement).value).toBe('200')
    await amounts[1].setValue(300)
    expect(wrapper.find('[data-testid="saldo-settle-submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('accounting.saldo_settle.amount_over_document')

    await amounts[1].setValue(200)
    await wrapper.find('[data-testid="saldo-settle-submit"]').trigger('click')
    await flushPromises()
    expect(m.create.mock.calls[0][0].allocations).toEqual([
      { line_id: 1, doc_type: 'purchase_invoice', doc_id: 7, amount: 800 },
      { line_id: 2, doc_type: 'purchase_invoice', doc_id: 7, amount: 200 },
    ])
  })

  it('hromadně rozdělí jeden zápis mezi označené doklady', async () => {
    m.candidates.mockResolvedValue(candidates([line(1, 1200)]))
    const wrapper = mount(SaldoSettleDialog, { props: { items: [target(7, 1000), target(8, 500)], asOf: '2099-12-31' } })
    await flushPromises()

    await wrapper.find('tbody input[type="radio"]').setValue(true)
    await wrapper.find('[data-testid="saldo-settle-submit"]').trigger('click')
    await flushPromises()
    expect(m.create.mock.calls[0][0].allocations).toEqual([
      { line_id: 1, doc_type: 'purchase_invoice', doc_id: 7, amount: 1000 },
      { line_id: 1, doc_type: 'purchase_invoice', doc_id: 8, amount: 200 },
    ])
  })

  it('uzavřené období přebije správce po potvrzení', async () => {
    m.admin = true
    m.candidates.mockResolvedValue(candidates([line(1, 1000, { amount_match: true, doc_match: true })]))
    m.create.mockReset()
    m.create
      .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'period_closed', message: 'x' } } } })
      .mockResolvedValueOnce({ created: [{ id: 1 }], failed: [] })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(SaldoSettleDialog, { props: { items: [target(7, 1000)], asOf: '2099-12-31' } })
    await flushPromises()

    await wrapper.find('[data-testid="saldo-settle-submit"]').trigger('click')
    await flushPromises()
    expect(confirm).toHaveBeenCalled()
    expect(m.create.mock.calls.map(c => c[1])).toEqual([false, true])
    expect(wrapper.emitted('done')).toHaveLength(1)
    confirm.mockRestore()
  })
})
