import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  partners: vi.fn(),
  open: vi.fn(),
  create: vi.fn(),
  toastError: vi.fn(),
  query: {} as Record<string, string>,
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listOffsets: m.list,
    offsetPartners: m.partners,
    offsetOpen: m.open,
    createOffset: m.create,
  },
}))
vi.mock('vue-router', () => ({ useRoute: () => ({ name: 'accounting-offsets', query: m.query }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v), formatDate: (v: string) => v }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))
vi.mock('@/components/ui/DateInput.vue', () => ({
  __esModule: true,
  default: { name: 'DateInput', props: ['modelValue'], template: '<input />' },
}))

import Offsets from '@/pages/accounting/Offsets.vue'

const item = (doc_type: 'invoice' | 'purchase_invoice', doc_id: number, remaining: number) => ({
  doc_type, doc_id, doc_no: `${doc_type}-${doc_id}`, issue_date: '2099-01-01', due_date: '2099-01-15', total: remaining, paid: 0, remaining,
})

describe('Offsets.vue — předvyplnění z odkazu', () => {
  beforeEach(() => {
    m.list.mockReset().mockResolvedValue([])
    m.partners.mockReset().mockResolvedValue([{ partner_id: 5, partner_name: 'Partner s.r.o.' }])
    m.open.mockReset().mockResolvedValue({
      partner_id: 5, partner_name: 'Partner s.r.o.',
      receivables: [item('invoice', 11, 1200), item('invoice', 12, 300)],
      payables: [item('purchase_invoice', 21, 800)],
    })
    m.create.mockReset().mockResolvedValue({})
    m.toastError.mockReset()
    m.query = {}
  })

  it('bez query zůstane jen seznam dohod', async () => {
    const wrapper = mount(Offsets)
    await flushPromises()
    expect(m.open).not.toHaveBeenCalled()
    expect(wrapper.find('select').exists()).toBe(false)
  })

  it('označí doklady z odkazu, částku omezí zbytkem a bez částky vezme celý zbytek', async () => {
    m.query = { partner_id: '5', docs: 'invoice:11:800,purchase_invoice:21,invoice:99:10' }
    const wrapper = mount(Offsets)
    await flushPromises()

    expect(m.open).toHaveBeenCalledWith(5)
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('5')
    const boxes = wrapper.findAll('tbody input[type="checkbox"]')
    expect(boxes.map(b => (b.element as HTMLInputElement).checked)).toEqual([true, false, true])
    const amounts = wrapper.findAll('tbody input[type="number"]').map(i => Number((i.element as HTMLInputElement).value))
    expect(amounts).toEqual([800, 300, 800])
    expect(wrapper.text()).toContain('accounting.offsets.balanced')
    expect(m.toastError).toHaveBeenCalledWith('accounting.offsets.prefill_missing:{"count":1}')
  })

  it('partnera mimo nabídku doplní do výběru', async () => {
    m.partners.mockResolvedValue([])
    m.query = { partner_id: '5', docs: 'invoice:12' }
    const wrapper = mount(Offsets)
    await flushPromises()
    expect(wrapper.findAll('option').map(o => o.text())).toContain('Partner s.r.o.')
  })
})
