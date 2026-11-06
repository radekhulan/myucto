import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import LegalProvisions504 from '../LegalProvisions504.vue'
import type { LegalProvisionsPreview, ReserveWorkspace } from '@/api/legalClosing504'

const api = vi.hoisted(() => ({ workspace: vi.fn(), ledgerWorkspace: vi.fn(), provisions: vi.fn(), provision: vi.fn(), reserve: vi.fn(), reverse: vi.fn() }))
vi.mock('@/api/legalClosing504', () => ({ legalClosing504Api: api }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))
const ActionBar = defineComponent({ props: ['actions'], template: '<div><template v-for="action in actions" :key="action.key"><button v-if="action.show !== false" :data-action="action.key" :disabled="action.disabled" @click="action.run()">{{ action.label }}</button></template></div>' })
const DimensionFields = defineComponent({ props: ['modelValue'], emits: ['update:modelValue'], template: '<button data-testid="set-dimensions" @click="$emit(\'update:modelValue\', { 1: 11, 2: 22, 3: 33, 4: null })">Set tuple</button>' })
const tuple = { 1: 11, 2: 22, 3: 33 }
const cell = () => ({ dimensions: { ...tuple }, cost_center: 'CENTER', project_id: 9, amount: '30000.00', available_cents: 15000000 })
const data = (): LegalProvisionsPreview => ({ accounting_framework: 'cz_504', as_of: '2097-12-31', items: [{ invoice_id: 31, document_no: 'SYN-31', partner_name: 'Synthetic partner', remaining: 150000, existing: { legal_amount: 30000, allocations: [cell()] }, source_joint: { available: true, cells: [cell()] } }], reserves: [{ entry_id: 81, document_no: 'SYN-81', reference: 'Reserve decision', cells: [cell()] }], pagination: { page: 1, per_page: 20, total: 1 } })
const workspace = (): ReserveWorkspace => ({ entries: [], assets: [], more_assets: false, lines: [], line_page: 1, more_lines: false, state: { current: null, revisions: [], blocking_entries: [] }, dimension_labels: { 11: 'House', 22: 'Unit', 33: 'Service' }, period: { row_version: 4, status: 'closing', ends_on: '2097-12-31' } })
const field = (wrapper: VueWrapper, key: string) => wrapper.get(`[data-testid="${key}"]`)
const action = (wrapper: VueWrapper, key: string) => wrapper.get(`[data-action="${key}"]`)
async function setup(props = {}, mode = 'provision') {
  const wrapper = mount(LegalProvisions504, { props: { periodId: 17, rowVersion: 4, canWrite: true, isClosing: true, ...props }, global: { stubs: { ActionBar, DimensionFields } } })
  await flushPromises()
  if (mode === 'release') await field(wrapper, 'legal-operation').setValue('release')
  await field(wrapper, 'legal-document').setValue(mode === 'release' ? '81' : '31')
  for (const [key, value] of Object.entries({ 'legal-reference': 'Decision 1', 'legal-basis': 'Legal basis', 'legal-evidence': 'Evidence' })) await field(wrapper, key).setValue(value)
  await field(wrapper, 'legal-taxable').setValue(true)
  await field(wrapper, 'legal-confirmed').setValue(true)
  return wrapper
}
async function submit(wrapper: VueWrapper) { await action(wrapper, 'preview').trigger('click'); await action(wrapper, 'save').trigger('click'); await flushPromises() }
beforeEach(() => {
  vi.resetAllMocks()
  api.workspace.mockImplementation(async () => workspace())
  api.ledgerWorkspace.mockImplementation(async () => workspace())
  api.provisions.mockImplementation(async () => data())
  api.provision.mockResolvedValue({})
  api.reserve.mockResolvedValue({ entry_id: 82 })
})

describe('LegalProvisions504', () => {
  it('reviews and submits source_joint with the full dimension, cost center and project tuple', async () => {
    const wrapper = await setup()
    await field(wrapper, 'cell-amount').setValue('30000.00')
    expect(action(wrapper, 'save').attributes('disabled')).toBeDefined()
    await action(wrapper, 'preview').trigger('click')
    expect(field(wrapper, 'legal-review').text()).toContain('House · Unit · Service')
    await action(wrapper, 'save').trigger('click'); await flushPromises()
    expect(api.provision).toHaveBeenCalledWith(17, 4, { invoice_id: 31, legal_amount: '30000.00', acct_amount: 0, legal_decision: { reference: 'Decision 1', legal_basis: 'Legal basis', evidence: 'Evidence', taxable_activity: true, conditions_confirmed: true, allocation_basis: 'source_joint', payment_allocation: 'proportional', allocations: [{ dimensions: tuple, cost_center: 'CENTER', project_id: 9, amount: 30000 }] } })
    expect(wrapper.emitted('updated')).toHaveLength(1)
  })

  it('invalidates review when amounts or decision evidence change', async () => {
    const wrapper = await setup(); await field(wrapper, 'cell-amount').setValue('30000.00')
    await action(wrapper, 'preview').trigger('click')
    await field(wrapper, 'cell-amount').setValue('30001.00')
    expect(wrapper.find('[data-testid="legal-review"]').exists()).toBe(false)
    await action(wrapper, 'save').trigger('click'); expect(api.provision).not.toHaveBeenCalled()
    await action(wrapper, 'preview').trigger('click')
    await field(wrapper, 'legal-evidence').setValue('Changed evidence')
    expect(action(wrapper, 'save').attributes('disabled')).toBeDefined()
  })

  it('supports a manual new decision and filters empty dimension slots', async () => {
    const wrapper = await setup()
    await field(wrapper, 'allocation-basis').setValue('new_decision')
    await field(wrapper, 'set-dimensions').trigger('click')
    await field(wrapper, 'cell-amount').setValue('25000.00')
    await submit(wrapper)
    const decision = api.provision.mock.calls[0]![2].legal_decision
    expect(decision.allocation_basis).toBe('new_decision')
    expect(decision).not.toHaveProperty('payment_allocation')
    expect(decision.allocations).toEqual([{ dimensions: tuple, cost_center: 'CENTER', project_id: 9, amount: 25000 }])
  })

  it('allows a zero target to release an existing provision without available source cells', async () => {
    const unavailable = data()
    unavailable.items[0]!.source_joint = { available: false, cells: [], finding: { message: 'Source no longer available' } }
    api.provisions.mockResolvedValueOnce(unavailable)
    const wrapper = await setup()
    expect(wrapper.text()).toContain('Source no longer available')
    await submit(wrapper)
    expect(api.provision).toHaveBeenCalledWith(17, 4, expect.objectContaining({ legal_amount: '0.00', legal_decision: expect.objectContaining({ allocations: [], allocation_basis: 'source_joint' }) }))
  })

  it('releases a reserve against the source entry and retains its complete tuple', async () => {
    const wrapper = await setup({}, 'release')
    await field(wrapper, 'cell-amount').setValue('30000.00')
    await submit(wrapper)
    expect(api.reserve).toHaveBeenCalledWith(17, { rule_key: 'reserve.repairs.release', row_version: 4, amount: '30000.00', description: 'Decision 1', legal_decision: { reference: 'Decision 1', legal_basis: 'Legal basis', evidence: 'Evidence', taxable_activity: true, conditions_confirmed: true, source_entry_id: 81, allocations: [{ dimensions: tuple, cost_center: 'CENTER', project_id: 9, amount: 30000 }] } })
    expect(api.provision).not.toHaveBeenCalled()
  })

  it.each(['-1', '150000.01'])('blocks invalid source allocation amount %s', async amount => {
    const wrapper = await setup(); await field(wrapper, 'cell-amount').setValue(amount)
    expect(action(wrapper, 'preview').attributes('disabled')).toBeDefined()
    await submit(wrapper)
    expect(api.provision).not.toHaveBeenCalled()
  })

  it.each([{ canWrite: false }, { isClosing: false }])('prevents writes for %j', async props => {
    const wrapper = await setup(props); await field(wrapper, 'cell-amount').setValue('30000.00')
    await action(wrapper, 'preview').trigger('click')
    const save = wrapper.find('[data-action="save"]')
    if (save.exists()) { expect(save.attributes('disabled')).toBeDefined(); await save.trigger('click') }
    expect(api.provision).not.toHaveBeenCalled()
  })

  it('shows rejected API errors, clears review and emits no success', async () => {
    api.provision.mockRejectedValueOnce({ response: { data: { error: { message: 'Allocation changed' } } } })
    const wrapper = await setup(); await field(wrapper, 'cell-amount').setValue('30000.00')
    await submit(wrapper)
    expect(wrapper.get('[role="alert"]').text()).toContain('Allocation changed')
    expect(wrapper.find('[data-testid="legal-review"]').exists()).toBe(false)
    expect(wrapper.emitted('updated')).toBeUndefined()
  })

  it('invalidates review when the period row version changes', async () => {
    const wrapper = await setup(); await field(wrapper, 'cell-amount').setValue('30000.00')
    await action(wrapper, 'preview').trigger('click')
    await wrapper.setProps({ rowVersion: 5 }); await flushPromises()
    expect(action(wrapper, 'save').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="legal-review"]').exists()).toBe(false)
  })

  it('does not submit a previously selected invoice absent from the refreshed period', async () => {
    const wrapper = await setup(); await field(wrapper, 'cell-amount').setValue('30000.00')
    await action(wrapper, 'preview').trigger('click')
    api.provisions.mockResolvedValueOnce({ ...data(), items: [] })
    await wrapper.setProps({ periodId: 18 }); await flushPromises()
    const review = wrapper.find('[data-action="preview"]')
    if (review.exists()) await review.trigger('click')
    const save = wrapper.find('[data-action="save"]')
    if (save.exists()) await save.trigger('click')
    await flushPromises()
    expect(api.provision).not.toHaveBeenCalled()
  })
})
