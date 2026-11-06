import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import AccessoryBindingForm from '../AccessoryBindingForm.vue'
import DebtDetailView from '../DebtDetailView.vue'
import { i18nPlugin, ops, stubs } from './helpers'
import type { DebtDetail } from '@/api/propertyPaymentOperations'

vi.mock('@/api/propertyPaymentOperations', async () => ({ propertyPaymentOperationsApi: (await import('./helpers')).ops }))
const global = () => ({ plugins: [i18nPlugin()], stubs })
const candidate = (id: number, over: Record<string, unknown> = {}) => ({ id, journal_entry_id: id + 100, date: '2026-03-01', document_number: `FV-${id}`, label: `Late fee ${id}`, account_code: '311', amount_cents: '12345', ...over })
const detail = (over: Partial<DebtDetail> = {}): DebtDetail => ({ id: 4, charge_id: 2, unit_id: 1, unit_label: 'Jednotka 1', title_ref: 'Advance', due_date: '2026-01-15', amount_cents: '10000', remaining_cents: '10000', legal: { basis: 'statutory', evidence: 'Law', regime: 'general' }, security: { kind: 'unsecured', evidence: 'None' }, liability_as_of: '2026-03-01', liability_complete: true, groups: [], lines: [], accessories: [], ...over })
beforeEach(() => { vi.clearAllMocks(); Object.values(ops).forEach(fn => fn.mockReset()) })

async function mounted(over: Partial<DebtDetail> = {}, canManage = true) {
  const wrapper = mount(AccessoryBindingForm, { props: { supplier: 1, detail: detail(over), canManage }, attachTo: document.body, global: global() })
  await flushPromises()
  return wrapper
}

describe('accessory receivable binding', () => {
  it('lists the real receivables by document, date, description, account and amount and pages by cursor', async () => {
    ops.accessoryCandidates.mockResolvedValueOnce({ items: [candidate(1), candidate(2)], next_cursor: 2, coverage: { eligibility: 'current_receipt_required' } }).mockResolvedValueOnce({ items: [candidate(3)], next_cursor: null, coverage: { eligibility: 'current_receipt_required' } })
    const wrapper = await mounted()
    expect(ops.accessoryCandidates).toHaveBeenNthCalledWith(1, 1, 0, 25)
    expect(wrapper.findAll('[data-row="accessory-candidate"]')[0]!.text()).toContain('FV-1')
    expect(wrapper.findAll('[data-row="accessory-candidate"]')[0]!.text()).toContain('Late fee 1')
    expect(wrapper.findAll('[data-row="accessory-candidate"]')[0]!.text()).toContain('311')
    expect(wrapper.findAll('[data-row="accessory-candidate"]')[0]!.text()).toContain('123.45 CZK')
    await wrapper.get('[data-action="accessory-more"]').trigger('click'); await flushPromises()
    expect(ops.accessoryCandidates).toHaveBeenNthCalledWith(2, 1, 2, 25)
    expect(wrapper.findAll('[data-row="accessory-candidate"]')).toHaveLength(3)
    expect(wrapper.find('[data-action="accessory-more"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('binds the chosen line with the component and both evidences exactly once per identical retry', async () => {
    ops.accessoryCandidates.mockResolvedValue({ items: [candidate(1), candidate(2)], next_cursor: null, coverage: { eligibility: 'current_receipt_required' } })
    ops.bindAccessory.mockResolvedValue({ id: 5 })
    const wrapper = await mounted()
    await wrapper.findAll('[data-field="accessory-select"]')[1]!.setValue(true)
    await wrapper.get('[data-action="accessory-bind"]').trigger('click'); await flushPromises()
    expect(ops.bindAccessory).not.toHaveBeenCalled()
    await wrapper.get('[data-field="accessory-component"]').setValue('default_interest')
    await wrapper.get('[data-field="accessory-evidence"]').setValue('Contract art. 4'); await wrapper.get('[data-field="accessory-liability"]').setValue('Both owners jointly')
    await wrapper.get('[data-action="accessory-bind"]').trigger('click'); await flushPromises()
    expect(ops.bindAccessory).toHaveBeenCalledWith(1, 4, { journal_entry_id: 102, journal_line_id: 2, component: 'default_interest', evidence: 'Contract art. 4', liability_evidence: 'Both owners jointly' })
    expect(wrapper.emitted('changed')).toHaveLength(1)
    expect(ops.accessoryCandidates).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })
  it('offers the old-interest purpose only for the separately evidenced resolution and only for interest', async () => {
    ops.accessoryCandidates.mockResolvedValue({ items: [candidate(1)], next_cursor: null, coverage: { eligibility: 'current_receipt_required' } })
    ops.bindAccessory.mockResolvedValue({ id: 5 })
    const plain = await mounted()
    await plain.get('[data-field="accessory-select"]').setValue(true); await plain.get('[data-field="accessory-component"]').setValue('interest')
    expect(plain.find('[data-field="accessory-old-effect"]').exists()).toBe(false)
    plain.unmount()
    const legal = { basis: 'statutory' as const, evidence: 'Law', regime: 'general' as const, component_disposition: { order: ['determined_cost', 'default_interest', 'interest', 'principal'] as never, debtor_evidence: 'Letter', old_interest_effect: { resolution: 'separately_evidenced' as const, evidence: 'Decision' } } }
    const wrapper = await mounted({ legal })
    await wrapper.get('[data-field="accessory-select"]').setValue(true); await wrapper.get('[data-field="accessory-component"]').setValue('default_interest')
    expect(wrapper.find('[data-field="accessory-old-effect"]').exists()).toBe(false)
    await wrapper.get('[data-field="accessory-component"]').setValue('interest')
    await wrapper.get('[data-field="accessory-old-effect"]').setValue(true)
    await wrapper.get('[data-field="accessory-evidence"]').setValue('Decision'); await wrapper.get('[data-field="accessory-liability"]').setValue('Group')
    await wrapper.get('[data-action="accessory-bind"]').trigger('click'); await flushPromises()
    expect(ops.bindAccessory.mock.calls[0]![2]).toMatchObject({ component: 'interest', purpose: 'old_1932_interest_effect' })
    wrapper.unmount()
  })
  it('keeps the same form blocked for read-only users and drops candidates answered for a previous company', async () => {
    let resolve!: (value: unknown) => void
    ops.accessoryCandidates.mockReturnValueOnce(new Promise(r => { resolve = r })).mockResolvedValueOnce({ items: [candidate(9)], next_cursor: null, coverage: { eligibility: 'current_receipt_required' } })
    const wrapper = mount(AccessoryBindingForm, { props: { supplier: 1, detail: detail(), canManage: true }, global: global() })
    await wrapper.setProps({ supplier: 2 }); await flushPromises()
    resolve({ items: [candidate(1)], next_cursor: null, coverage: { eligibility: 'current_receipt_required' } }); await flushPromises()
    expect(wrapper.findAll('[data-row="accessory-candidate"]').map(r => r.text())[0]).toContain('FV-9')
    expect(wrapper.findAll('[data-row="accessory-candidate"]')).toHaveLength(1)
    const view = mount(DebtDetailView, { props: { supplier: 1, detail: detail(), canManage: false }, global: global() })
    expect(view.find('[data-section="accessory-binding"]').exists()).toBe(false)
  })
  it('shows liability day and incompleteness of the debt detail', () => {
    ops.accessoryCandidates.mockResolvedValue({ items: [], next_cursor: null, coverage: { eligibility: 'current_receipt_required' } })
    const view = mount(DebtDetailView, { props: { supplier: 1, detail: detail({ liability_complete: false }), canManage: false }, global: global() })
    expect(view.find('[data-flag="liability-as-of"]').exists()).toBe(true)
    expect(view.find('[data-flag="liability-incomplete"]').exists()).toBe(true)
    expect(mount(DebtDetailView, { props: { supplier: 1, detail: detail(), canManage: false }, global: global() }).find('[data-flag="liability-incomplete"]').exists()).toBe(false)
  })
  it('refuses to write without the manage capability', async () => {
    ops.accessoryCandidates.mockResolvedValue({ items: [candidate(1)], next_cursor: null, coverage: { eligibility: 'current_receipt_required' } })
    const wrapper = await mounted({}, false)
    expect(wrapper.get<HTMLButtonElement>('[data-action="accessory-bind"]').element.disabled).toBe(true)
    wrapper.unmount()
  })
})
