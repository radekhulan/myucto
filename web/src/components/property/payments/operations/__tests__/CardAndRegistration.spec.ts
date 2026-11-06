import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import CardPanel from '../CardPanel.vue'
import DebtRegistrationForm from '../DebtRegistrationForm.vue'
import DebtsPanel from '../DebtsPanel.vue'
import { i18nPlugin, ops, page, stubs, token } from './helpers'

vi.mock('@/api/propertyPaymentOperations', async () => ({ propertyPaymentOperationsApi: (await import('./helpers')).ops }))
const global = () => ({ plugins: [i18nPlugin()], stubs })
beforeEach(() => { vi.clearAllMocks(); Object.values(ops).forEach(fn => fn.mockReset()) })

const card = {
  as_of: '2026-02-01T12:00:00+01:00', known_at: '2026-02-02T08:00:00+01:00', scope: 'house', precision: 'day', snapshot_hash: 'h',
  coverage: { financial_complete: false, service_settlements: 'pending', opening_import: 'not_implemented', scope: 'recorded_s3_s4', complete: false },
  totals: { gross_receivable_cents: '15000', gross_credit_cents: null, reserved_credit_cents: '0', available_credit_cents: null, guarantee_exposure_cents: null },
  claims: [
    { claim_key: 'claim.4', debt_id: 4, unit_id: 1, unit_label: 'Jednotka 1', title_ref: 'Advance 01/2026', due_date: '2026-01-15', amount_cents: '15000', person_liability_cents: null, precision: 'day', complete: false,
      groups: [{ group_key: 'sjm', role: 'debtor', members: [{ id: 21, label: 'Spouse A' }, { id: 22, label: 'Spouse B' }], remaining_cap_cents: '15000' }] },
  ],
  credits: [{ id: 3, unit_id: 1, unit_label: 'Jednotka 1', parties: [{ id: 21, label: 'Spouse A' }], gross_credit_cents: null, reserved_credit_cents: '0', available_credit_cents: null, precision: 'instant', complete: true }],
}

describe('receivables and credits card', () => {
  async function load() {
    ops.card.mockResolvedValue(card)
    const wrapper = mount(CardPanel, { props: { supplier: 1, asOf: '2026-02-01T12:00:00+01:00', units: [] }, attachTo: document.body, global: global() })
    await wrapper.get('[data-field="card-known-at"] input').setValue('2026-02-02T08:00:00+01:00')
    await wrapper.get('[data-action="card-load"]').trigger('click'); await flushPromises()
    return wrapper
  }
  it('requests the card for the explicit as-of and known-at instants', async () => {
    const wrapper = await load()
    expect(ops.card).toHaveBeenCalledWith(1, { as_of: '2026-02-01T12:00:00+01:00', known_at: '2026-02-02T08:00:00+01:00' })
    wrapper.unmount()
  })
  it('never turns an unknown total into zero and keeps gross values un-netted', async () => {
    const wrapper = await load()
    expect(wrapper.get('[data-total="gross_receivable_cents"]').text()).toBe('150.00 CZK')
    expect(wrapper.get('[data-total="gross_credit_cents"]').text()).toBe('propertyPayments.unknown')
    expect(wrapper.get('[data-total="available_credit_cents"]').attributes('data-unknown')).toBe('true')
    expect(wrapper.get('[data-total="reserved_credit_cents"]').text()).toBe('0.00 CZK')
    expect(wrapper.get('[data-claim-person]').text()).toContain('propertyPayments.unknown')
    expect(wrapper.find('[data-flag="day-precision"]').exists()).toBe(true)
    expect(wrapper.get('[data-flag="coverage"]').text()).toContain('propertyPayments.card.financialIncomplete')
    expect(wrapper.get('[data-flag="coverage"]').text()).toContain('propertyPayments.card.servicesMissing')
    wrapper.unmount()
  })
  it('shows a joint spouse group once with its members instead of multiplying the claim', async () => {
    const wrapper = await load()
    expect(wrapper.findAll('[data-row="card-claim"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-claim-amount]')).toHaveLength(1)
    const groups = wrapper.findAll('[data-group]')
    expect(groups).toHaveLength(1)
    expect(groups[0]!.text()).toContain('Spouse A, Spouse B')
    expect(wrapper.text().match(/150\.00 CZK/g)).toHaveLength(3)
    expect(wrapper.get('[data-row="card-credit"]').text()).toContain('propertyPayments.unknown')
    wrapper.unmount()
  })
  it('ignores a card that answers after the as-of instant changed', async () => {
    let resolve!: (value: unknown) => void
    ops.card.mockReturnValue(new Promise(r => { resolve = r }))
    const wrapper = mount(CardPanel, { props: { supplier: 1, asOf: '2026-02-01T12:00:00+01:00', units: [] }, attachTo: document.body, global: global() })
    await wrapper.get('[data-field="card-known-at"] input').setValue('2026-02-02T08:00:00+01:00')
    await wrapper.get('[data-action="card-load"]').trigger('click')
    await wrapper.setProps({ asOf: '2026-03-01T12:00:00+01:00' })
    resolve(card); await flushPromises()
    expect(wrapper.find('[data-section="card-result"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('debt registration', () => {
  const charge = { id: 8, row_version: 2, unit_id: 1, unit_label: 'Jednotka 1', accounting_date: '2026-02-01', due_date: '2026-02-15', status: 'posted', amount_cents: '30000', previous_charge_id: null }
  const detail = { ...charge, parties: [{ id: 21, label: 'Owner A' }], lines: [{ id: 1, label: 'Advance', amount_cents: '20000', component_id: 1, registered_debt_id: null }, { id: 2, label: 'Fund', amount_cents: '10000', component_id: 2, registered_debt_id: 77 }] }
  it('registers only the explicitly selected unbound line with the real legal facts', async () => {
    ops.list.mockResolvedValue(page([charge])); ops.detail.mockResolvedValue(detail); ops.registerDebt.mockResolvedValue({ id: 5 })
    const wrapper = mount(DebtRegistrationForm, { props: { supplier: 1, unitId: 0, canManage: true }, attachTo: document.body, global: global() })
    await flushPromises(); await wrapper.get('[data-field="charge-select"]').trigger('change'); await flushPromises()
    const boxes = wrapper.findAll<HTMLInputElement>('[data-field="principal-line"]')
    expect(boxes.map(b => b.element.disabled)).toEqual([false, true])
    await boxes[0]!.setValue(true)
    await wrapper.get('[data-field="title-ref"]').setValue('Advance 02/2026'); await wrapper.get('[data-field="debt-due"]').setValue('2026-02-15')
    await wrapper.get('[data-field="legal-basis"]').setValue('legal_act'); await wrapper.get('[data-field="legal-act-date"]').setValue('2026-01-05'); await wrapper.get('[data-field="legal-evidence"]').setValue('Assembly resolution')
    await wrapper.get('[data-field="security-kind"]').setValue('unsecured'); await wrapper.get('[data-field="security-evidence"]').setValue('No security')
    await wrapper.get('[data-action="register"]').trigger('click'); await flushPromises()
    expect(ops.registerDebt).toHaveBeenCalledWith(1, { charge_id: 8, expected_version: 2, principal_line_ids: [1], title_ref: 'Advance 02/2026', due_date: '2026-02-15', legal: { basis: 'legal_act', evidence: 'Assembly resolution', regime: 'general', legal_act_date: '2026-01-05' }, security: { kind: 'unsecured', evidence: 'No security' } }, expect.stringMatching(token))
    expect(wrapper.emitted('changed')).toHaveLength(1)
    wrapper.unmount()
  })
  it('shows the server review message when an old-interest receipt is missing', async () => {
    ops.list.mockResolvedValue(page([charge])); ops.detail.mockResolvedValue(detail)
    ops.registerDebt.mockRejectedValue({ response: { data: { error: { message: 'Review needed.', details: ['An accessory receipt for the older interest is required.'] } } } })
    const wrapper = mount(DebtRegistrationForm, { props: { supplier: 1, unitId: 0, canManage: true }, attachTo: document.body, global: global() })
    await flushPromises(); await wrapper.get('[data-field="charge-select"]').trigger('change'); await flushPromises()
    await wrapper.findAll('[data-field="principal-line"]')[0]!.setValue(true)
    await wrapper.get('[data-field="title-ref"]').setValue('T'); await wrapper.get('[data-field="debt-due"]').setValue('2026-02-15'); await wrapper.get('[data-field="legal-evidence"]').setValue('Law')
    await wrapper.get('[data-field="security-kind"]').setValue('secured'); await wrapper.get('[data-field="security-evidence"]').setValue('Pledge')
    await wrapper.get('[data-action="register"]').trigger('click'); await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Review needed. An accessory receipt for the older interest is required.')
    wrapper.unmount()
  })
})

describe('debt list', () => {
  it('opens a debt detail with a joint group displayed once', async () => {
    ops.list.mockResolvedValue(page([{ id: 4, charge_id: 2, unit_id: 1, unit_label: 'Jednotka 1', title_ref: 'Advance', due_date: '2026-01-15', amount_cents: '10000', remaining_cents: '4000' }]))
    ops.detail.mockResolvedValue({ id: 4, charge_id: 2, unit_id: 1, unit_label: 'Jednotka 1', title_ref: 'Advance', due_date: '2026-01-15', amount_cents: '10000', remaining_cents: '4000', legal: { basis: 'statutory', evidence: 'Law', regime: 'general' }, security: { kind: 'unsecured', evidence: 'None' }, liability_as_of: '2026-03-01', liability_complete: true, groups: [{ group_key: 'sjm', role: 'debtor', members: [{ id: 21, label: 'Spouse A' }, { id: 22, label: 'Spouse B' }], remaining_cap_cents: '4000' }], lines: [{ id: 1, label: 'Advance', amount_cents: '10000', remaining_cents: '4000' }], accessories: [] })
    const wrapper = mount(DebtsPanel, { props: { supplier: 1, unitId: 0, reloadKey: 0, canManage: false }, global: global() })
    await flushPromises(); await wrapper.get('[data-action="debt-open"]').trigger('click'); await flushPromises()
    expect(ops.detail).toHaveBeenCalledWith(1, 'debts', 4)
    expect(wrapper.findAll('[data-group]')).toHaveLength(1)
    expect(wrapper.get('[data-section="debt-detail"]').text()).toContain('Spouse A, Spouse B')
  })
})
