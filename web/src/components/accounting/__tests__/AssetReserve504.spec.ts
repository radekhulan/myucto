import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import AssetReserve504 from '../AssetReserve504.vue'
import type { ReservePreview, ReserveWorkspace } from '@/api/legalClosing504'

const api = vi.hoisted(() => ({ workspace: vi.fn(), preview: vi.fn(), correctionPreview: vi.fn(), reserve: vi.fn(), correct: vi.fn(), reverse: vi.fn() }))
vi.mock('@/api/legalClosing504', () => ({ legalClosing504Api: api }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => key + (params ? ':' + JSON.stringify(params) : '') }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value), formatDate: (value: string) => value }))

const ActionBar = defineComponent({ props: ['actions'], template: '<div><template v-for="action in actions" :key="action.key"><button v-if="action.show !== false" :data-action="action.key" :disabled="action.disabled" @click="action.run()">{{ action.label }}</button></template></div>' })
const DateInput = defineComponent({ props: ['modelValue'], emits: ['update:modelValue'], template: '<input data-testid="original-date" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' })
const tuple = { 1: 11, 2: 22, 3: 33 }
const receipt = () => ({ source_hash: 'verified-source-hash', asset_id: 7, mode: 'capitalization' as const, cells: [{ dimensions: { ...tuple }, cost_center: 'CENTER', project_id: 9, weight_cents: 15000000 }], contributions: [{ line_id: 71, contribution_cents: 15000000, dimensions: { ...tuple } }] })
const workspace = (): ReserveWorkspace => ({ entries: [], assets: [{ id: 7, inventory_number: 'SYN-7', name: 'Synthetic asset', input_price: '200000.00' }], more_assets: false, lines: [{ line_id: 71, entry_id: 70, document_no: 'SYN-70', entry_date: '2096-01-01', description: 'Synthetic capitalization', amount: '200000.00' }], line_page: 1, more_lines: false, state: { current: { ...receipt(), source_hash: 'previous-source-hash' }, revisions: [], blocking_entries: [] }, dimension_labels: { 11: 'House', 22: 'Unit', 33: 'Service' }, period: { row_version: 4, status: 'closing', ends_on: '2097-12-31' } })
const preview = (): ReservePreview => ({ source: receipt(), allocations: [{ dimensions: { ...tuple }, cost_center: 'CENTER', project_id: 9, amount: '150000.00' }] })
const action = (wrapper: VueWrapper, key: string) => wrapper.get(`[data-action="${key}"]`)
const field = (wrapper: VueWrapper, key: string) => wrapper.get(`[data-testid="${key}"]`)
function labelled(wrapper: VueWrapper, key: string) { return wrapper.findAll('label').find(label => label.text() === `accounting.legal504.${key}`)!.get('input, textarea') }
async function setup(props = {}) {
  const wrapper = mount(AssetReserve504, { props: { periodId: 17, rowVersion: 4, canWrite: true, isClosing: true, ...props }, global: { stubs: { ActionBar, DateInput } } })
  await flushPromises()
  await field(wrapper, 'asset-select').setValue('7')
  await flushPromises()
  await field(wrapper, 'continued').setValue('Asset remains in use for the original purpose')
  return wrapper
}
async function creation(wrapper: VueWrapper) {
  for (const [key, value] of Object.entries({ 'reserve-amount': '150000.00', 'reserve-description': 'Synthetic reserve', 'legal-reference': 'Decision 1', 'legal-basis': 'Legal basis', 'legal-evidence': 'Evidence' })) await field(wrapper, key).setValue(value)
  await field(wrapper, 'legal-taxable').setValue(true)
  await field(wrapper, 'legal-confirmed').setValue(true)
}
async function correction(wrapper: VueWrapper, mode: string) {
  await field(wrapper, 'reserve-operation').setValue(mode)
  await field(wrapper, 'correction-reason').setValue('Correct source association')
  if (mode === 'prospective_closed_history') {
    await field(wrapper, 'original-date').setValue('2096-06-30')
    await labelled(wrapper, 'original_reference').setValue('Original decision')
    await labelled(wrapper, 'original_evidence').setValue('Original purpose evidence')
    await labelled(wrapper, 'original_confirmed').setValue(true)
  }
}
beforeEach(() => {
  vi.resetAllMocks()
  api.workspace.mockImplementation(async () => workspace())
  api.preview.mockImplementation(async () => preview())
  api.correctionPreview.mockImplementation(async () => ({ ...preview(), remaining_cents: 12000000 }))
  api.reserve.mockResolvedValue({ entry_id: 80 })
  api.correct.mockResolvedValue({ revision_number: 2 })
})

describe('AssetReserve504', () => {
  it('requires preview, shows the complete tuple and submits its source hash', async () => {
    const wrapper = await setup()
    await creation(wrapper)
    expect(action(wrapper, 'save').attributes('disabled')).toBeDefined()
    await action(wrapper, 'preview').trigger('click'); await flushPromises()
    expect(field(wrapper, 'reserve-preview').text()).toContain('House · Unit · Service')
    expect(field(wrapper, 'reserve-preview').text()).toContain('CENTER')
    expect(field(wrapper, 'reserve-preview').text()).toContain('accounting.legal504.project:' + JSON.stringify({ id: 9 }))
    expect(field(wrapper, 'reserve-preview').text()).toContain('150000')
    const requestedSource = structuredClone(api.preview.mock.calls[0]![1])
    await action(wrapper, 'save').trigger('click'); await flushPromises()
    expect(api.reserve).toHaveBeenCalledOnce()
    expect(api.reserve).toHaveBeenCalledWith(17, expect.objectContaining({ rule_key: 'reserve.repairs.create', row_version: 4, legal_decision: expect.objectContaining({ reserve_source: { ...requestedSource, source_hash: 'verified-source-hash' } }) }))
    expect(api.preview.mock.calls[0]![1]).not.toHaveProperty('source_hash')
    expect(wrapper.emitted('updated')).toHaveLength(1)
  })

  it('invalidates a preview when an input or row version changes', async () => {
    const wrapper = await setup(); await creation(wrapper)
    await action(wrapper, 'preview').trigger('click'); await flushPromises()
    await field(wrapper, 'continued').setValue('Changed evidence')
    expect(wrapper.find('[data-testid="reserve-preview"]').exists()).toBe(false)
    await action(wrapper, 'save').trigger('click')
    expect(api.reserve).not.toHaveBeenCalled()
    await action(wrapper, 'preview').trigger('click'); await flushPromises()
    await wrapper.setProps({ rowVersion: 5 }); await flushPromises()
    expect(action(wrapper, 'save').attributes('disabled')).toBeDefined()
  })

  it('discards a preview response for stale input', async () => {
    let resolve!: (value: ReservePreview) => void
    api.preview.mockReturnValueOnce(new Promise<ReservePreview>(done => { resolve = done }))
    const wrapper = await setup(); await creation(wrapper)
    await action(wrapper, 'preview').trigger('click')
    await field(wrapper, 'continued').setValue('Changed while request pending')
    resolve(preview()); await flushPromises()
    expect(wrapper.find('[data-testid="reserve-preview"]').exists()).toBe(false)
    expect(action(wrapper, 'save').attributes('disabled')).toBeDefined()
  })

  it.each([{ canWrite: false }, { isClosing: false }])('prevents submission for %j', async props => {
    const wrapper = await setup(props); await creation(wrapper)
    await action(wrapper, 'preview').trigger('click'); await flushPromises()
    const save = wrapper.find('[data-action="save"]')
    if (save.exists()) { expect(save.attributes('disabled')).toBeDefined(); await save.trigger('click') }
    expect(api.reserve).not.toHaveBeenCalled()
  })

  it('shows a rejected server write and does not emit success', async () => {
    api.reserve.mockRejectedValueOnce({ response: { data: { error: { message: 'Source changed' } } } })
    const wrapper = await setup(); await creation(wrapper)
    await action(wrapper, 'preview').trigger('click'); await flushPromises()
    await action(wrapper, 'save').trigger('click'); await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Source changed')
    expect(wrapper.emitted('updated')).toBeUndefined()
    expect(wrapper.find('[data-testid="reserve-preview"]').exists()).toBe(false)
  })

  it.each(['same_period', 'prospective_closed_history'])('submits the %s correction with original purpose only for V2', async mode => {
    const wrapper = await setup(); await correction(wrapper, mode)
    await action(wrapper, 'preview').trigger('click'); await flushPromises()
    const expected = structuredClone(api.correctionPreview.mock.calls[0]![1])
    expect(expected).toMatchObject({ mode, previous_hash: 'previous-source-hash', reason: 'Correct source association' })
    if (mode === 'prospective_closed_history') expect(expected.original_purpose).toEqual({ same_original_asset_and_purpose: true, document_date: '2096-06-30', reference: 'Original decision', evidence: 'Original purpose evidence' })
    else expect(expected).not.toHaveProperty('original_purpose')
    await action(wrapper, 'save').trigger('click'); await flushPromises()
    expect(api.correct).toHaveBeenCalledWith(17, { ...expected, row_version: 4, reserve_source: { ...expected.reserve_source, source_hash: 'verified-source-hash' } })
    expect(api.reserve).not.toHaveBeenCalled()
  })

  it('passes documented contributions as cents and invalidates their edited preview', async () => {
    const wrapper = await setup(); await creation(wrapper)
    await field(wrapper, 'source-mode').setValue('documented_association')
    await labelled(wrapper, 'association_reference').setValue('Association 1')
    await labelled(wrapper, 'association_evidence').setValue('Association evidence')
    await labelled(wrapper, 'contribution').setValue('12345.67')
    await action(wrapper, 'preview').trigger('click'); await flushPromises()
    expect(api.preview.mock.calls[0]![1].association).toEqual({ reference: 'Association 1', evidence: 'Association evidence', contributions: [{ line_id: 71, contribution_cents: 1234567 }] })
    await labelled(wrapper, 'contribution').setValue('12345.68')
    expect(wrapper.find('[data-testid="reserve-preview"]').exists()).toBe(false)
    expect(action(wrapper, 'save').attributes('disabled')).toBeDefined()
  })
})
