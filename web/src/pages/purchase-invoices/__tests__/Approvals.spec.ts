import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PurchaseApprovalRow } from '@/api/purchaseApprovals'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  count: vi.fn(),
  decide: vi.fn(),
  remind: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  accountant: false,
}))

vi.mock('@/api/purchaseApprovals', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/purchaseApprovals')>()),
  purchaseApprovalsApi: { list: m.list, count: m.count, decide: m.decide, remind: m.remind },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    user: { id: 7 },
    canRead: () => true,
    canWrite: (key: string) => key === 'purchase_invoices' ? m.accountant : true,
  }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.toastSuccess, error: m.toastError }) }))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import Approvals from '@/pages/purchase-invoices/Approvals.vue'

function row(id: number, approverId: number, status: PurchaseApprovalRow['status'] = 'pending'): PurchaseApprovalRow {
  return {
    id, purchase_invoice_id: 100 + id, round: 1, status, amount_czk: 1000,
    dimension_value: { id: 5, code: 'PROVOZ', name: 'Provoz', type_name: 'Středisko' },
    approver: { id: approverId, name: 'Jana Syntetická', email: 'jana@example.test' },
    requested_at: '2026-09-01', decided_at: null, decided_via: null, comment: null,
    invoice: {
      id: 100 + id, document_number: `FP${id}`, supplier_name: 'Dodavatel s.r.o.', issue_date: '2026-09-01', due_date: '2026-09-15',
      total_without_vat: 1000, total_with_vat: 1210, currency: 'CZK', has_pdf: true, status: 'draft', approval_status: 'pending',
    },
  }
}

const stubs = { Modal: { template: '<div><slot /><slot name="footer" /></div>' } }

describe('Approvals.vue', () => {
  beforeEach(() => {
    for (const fn of [m.list, m.count, m.decide, m.remind, m.toastSuccess, m.toastError]) fn.mockReset()
    m.accountant = false
    m.count.mockResolvedValue(0)
    m.list.mockResolvedValue({ data: [row(1, 7), row(2, 99)], meta: { total: 2, page: 1, pages: 1 } })
  })

  it('schvalovateli ukáže akce jen u jeho dokladů a načte scope=mine', async () => {
    const wrapper = mount(Approvals, { global: { stubs } })
    await flushPromises()
    expect(m.list).toHaveBeenCalledWith({ status: 'pending', scope: 'mine', page: 1 })
    expect(wrapper.find('[data-test="approval-row-1"] [data-test="approval-approve"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="approval-row-2"] [data-test="approval-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="approvals-scope"]').exists()).toBe(false)
  })

  it('účetní má přepínač Moje / Vše a tlačítko Připomenout', async () => {
    m.accountant = true
    const wrapper = mount(Approvals, { global: { stubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="approvals-scope"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="approval-row-2"] [data-test="approval-remind"]').exists()).toBe(true)
    await wrapper.get('[data-test="approvals-scope-all"]').trigger('click')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith({ status: 'pending', scope: 'all', page: 1 })
  })

  it('schválení volá decide(approve) a přenačte seznam', async () => {
    m.decide.mockResolvedValue({ ...row(1, 7, 'approved'), invoice_approval_status: 'pending' })
    const wrapper = mount(Approvals, { global: { stubs } })
    await flushPromises()
    await wrapper.get('[data-test="approval-row-1"] [data-test="approval-approve"]').trigger('click')
    await flushPromises()
    expect(m.decide).toHaveBeenCalledWith(1, { decision: 'approve' })
    expect(m.toastSuccess).toHaveBeenCalledWith('purchase_approval.toast.approved')
    expect(m.list).toHaveBeenCalledTimes(2)
  })

  it('zamítnutí bez důvodu nic neodešle, s důvodem pošle comment', async () => {
    m.decide.mockResolvedValue({ ...row(1, 7, 'rejected'), invoice_approval_status: 'rejected' })
    const wrapper = mount(Approvals, { global: { stubs } })
    await flushPromises()
    await wrapper.get('[data-test="approval-row-1"] [data-test="approval-reject"]').trigger('click')
    await wrapper.get('[data-test="approval-reject-confirm"]').trigger('click')
    await flushPromises()
    expect(m.decide).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('purchase_approval.reject_dialog.reason_required')

    await wrapper.get('[data-test="approval-reject-reason"]').setValue('Špatné středisko')
    await wrapper.get('[data-test="approval-reject-confirm"]').trigger('click')
    await flushPromises()
    expect(m.decide).toHaveBeenCalledWith(1, { decision: 'reject', comment: 'Špatné středisko' })
    expect(m.toastSuccess).toHaveBeenCalledWith('purchase_approval.toast.rejected')
  })

  it('karta na telefonu nabízí Schválit a Zamítnout jako první velká tlačítka', async () => {
    m.decide.mockResolvedValue({ ...row(1, 7, 'approved'), invoice_approval_status: 'approved' })
    const wrapper = mount(Approvals, { global: { stubs } })
    await flushPromises()
    const actions = wrapper.get('[data-test="approval-card-1"] [data-test="approval-card-actions"]')
    expect(actions.classes()).toEqual(expect.arrayContaining(['max-md:grid', 'max-md:grid-cols-2', 'max-md:[&>*]:h-10']))
    const approve = actions.get('[data-test="approval-card-approve"]')
    expect(approve.classes()).toContain('max-md:order-1')
    expect(actions.get('[data-test="approval-card-reject"]').classes()).toContain('max-md:order-2')
    expect(wrapper.find('[data-test="approval-card-2"] [data-test="approval-card-approve"]').exists()).toBe(false)
    await approve.trigger('click')
    await flushPromises()
    expect(m.decide).toHaveBeenCalledWith(1, { decision: 'approve' })
    await wrapper.get('[data-test="approval-card-1"] [data-test="approval-card-reject"]').trigger('click')
    expect(wrapper.find('[data-test="approval-reject-dialog"]').exists()).toBe(true)
  })

  it('filtry drží na telefonu v jednom řádku, přepínač rozsahu jako ikony', async () => {
    m.accountant = true
    const wrapper = mount(Approvals, { global: { stubs } })
    await flushPromises()
    expect(wrapper.get('[data-test="approvals-filters"]').classes()).toContain('max-md:flex-nowrap')
    const all = wrapper.get('[data-test="approvals-scope-all"]')
    expect(all.classes()).toEqual(expect.arrayContaining(['max-md:h-10', 'max-md:w-10']))
    expect(all.attributes('title')).toBe('purchase_approval.scope.all')
    expect(all.get('span').classes()).toContain('max-md:sr-only')
  })
})
