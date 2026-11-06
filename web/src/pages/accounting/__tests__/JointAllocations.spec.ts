import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'

const mocks = vi.hoisted(() => ({ keys: vi.fn(), candidates: vi.fn(), preview: vi.fn(), apply: vi.fn(), batches: vi.fn(), detail: vi.fn(), cancel: vi.fn(), get: vi.fn(), write: true, supplier: { currentSupplierId: 1 } }))
vi.mock('@/api/jointAllocations', () => ({ jointAxes: ['activity', 'activity_kind', 'tax_regime'], jointAllocationsApi: mocks }))
vi.mock('@/api/client', () => ({ api: { get: mocks.get } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => mocks.write }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => reactive(mocks.supplier) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: { value: 'en' } }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value, formatMoney: (value: number, currency: string) => `${value} ${currency}` }))

import JointAllocations from '../JointAllocations.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { useSupplierStore } from '@/stores/supplier'

const version = { id: 3, key_id: 2, code: 'ROOMS', name: 'Synthetic measured rooms', version_no: 1, valid_from: '2096-01-01', valid_to: '2097-01-01',
  basis: 'fixed', explanation: 'Synthetic documented rooms', allocation_mode: 'explicit_amounts', cell_ids: ['a'.repeat(64), 'b'.repeat(64)],
  cells: [{ activity: 11, activity_kind: 21, tax_regime: 31, weight: '1', additional_dimensions: { 9: 91 } },
    { activity: 12, activity_kind: 22, tax_regime: 31, weight: '1', additional_dimensions: { 9: 92 } }] }
const candidate = { id: 88, entry_id: 9, source_date: '2096-03-01', source_type: 'manual', source_id: null, document_no: 'SYN-ROOM-01', entry_description: 'Synthetic rent',
  account_code: '518', account_name: 'Synthetic services', side: 'debit', amount: '2.00', amount_foreign: '0.10', currency_code: 'EUR', fx_rate: '20', is_red_storno: false,
  dimensions: {}, dimension_splits: { 9: { 91: 0.5, 92: 0.5 } } }
const target = { account_id: 1, side: 'debit', amount: '1.00', amount_foreign: '0.05', currency_code: 'EUR', is_red_storno: false, dimensions: { 1: 11, 2: 21, 3: 31, 9: 91 } }
const preview = { preview_hash: 'c'.repeat(64), source_lines: [candidate], target_date: '2096-06-30', key: version,
  semantic_values: { 11: { name: 'Synthetic main' }, 21: { name: 'Synthetic sports' }, 31: { name: 'Synthetic preliminary tax' }, 91: { name: 'Synthetic room A' } },
  plan: { sources: [{ source_line_id: 88, compensation: { ...target, side: 'credit', amount: '2.00' }, targets: [target, target] }] } }
const batch = { id: 33, target_date: '2096-06-30', reason: 'Synthetic actual use', status: 'applied', journal_entry_id: 50, created_at: '2096-06-30' }
let wrapper: VueWrapper | null = null
const button = (key: string) => wrapper!.findAll('button').find(item => item.text() === `jointAllocations.${key}`)!
const section = (key: string) => wrapper!.findAll('section').find(item => item.find('h2').text().includes(`jointAllocations.${key}`))!

beforeEach(() => {
  vi.clearAllMocks()
  mocks.write = true
  useSupplierStore().currentSupplierId = 1
  mocks.keys.mockResolvedValue([version]); mocks.batches.mockResolvedValue([])
  mocks.get.mockResolvedValue({ data: { types: [], values: [{ id: 91, name: 'Synthetic room A' }, { id: 92, name: 'Synthetic room B' }] } })
  mocks.candidates.mockResolvedValue({ items: [candidate], next_before_id: null })
  mocks.preview.mockResolvedValue(preview); mocks.apply.mockResolvedValue(batch)
  mocks.detail.mockResolvedValue({ ...batch, snapshot: preview, cancellation: null, audit: [] })
  mocks.cancel.mockResolvedValue({ ...batch, status: 'cancelled' })
})
afterEach(() => { wrapper?.unmount(); wrapper = null })
async function searchSources() {
  const form = wrapper!.find('form')
  if (form.exists()) await form.trigger('submit')
  else await button('findSources').trigger('click')
}
function render(realDates = false) {
  wrapper = mount(JointAllocations, { global: { stubs: {
    RouterLink: { template: '<a><slot /></a>' },
    DateInput: realDates ? false : { props: ['modelValue'], emits: ['update:modelValue'], template: '<input class="date-stub" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
    JointAllocationKeyEditor: true,
  } } })
}

describe('Joint allocation workflow', () => {
  it('selects readable source documents and posts the reviewed exact tuple, then cancels it', async () => {
    render(); await flushPromises()
    await wrapper!.find('select').setValue('3')
    await wrapper!.find('.date-stub').setValue('2096-06-30')
    await searchSources(); await flushPromises()
    expect(wrapper!.text()).toContain('SYN-ROOM-01 · 518 · Synthetic services')
    expect(mocks.candidates).toHaveBeenCalledWith(1, expect.objectContaining({ target_date: '2096-06-30' }))
    await wrapper!.find('input[type="checkbox"]').setValue(true); await flushPromises()
    const exact = section('exactCells')
    await exact.find('textarea').setValue('Synthetic room measurements and booked FX margins')
    const amounts = exact.findAll('input')
    await amounts[0]!.setValue('1.00'); await amounts[1]!.setValue('0.05')
    await amounts[2]!.setValue('1.00'); await amounts[3]!.setValue('0.05')
    await wrapper!.findAll('textarea').at(-1)!.setValue('Synthetic actual use')
    await button('preview').trigger('click'); await flushPromises()
    expect(mocks.preview).toHaveBeenCalledWith(1, expect.objectContaining({ exact_cells: [{ source_line_id: 88,
      evidence: 'Synthetic room measurements and booked FX margins', cells: [
        { cell_id: 'a'.repeat(64), amount: '1.00', amount_foreign: '0.05' }, { cell_id: 'b'.repeat(64), amount: '1.00', amount_foreign: '0.05' },
      ] }] }))
    await button('apply').trigger('click'); await flushPromises()
    expect(mocks.apply).toHaveBeenCalledWith(1, expect.objectContaining({ preview_hash: 'c'.repeat(64), source_line_ids: [88], reason: 'Synthetic actual use' }))
    const form = wrapper!.findAll('form').at(-1)!
    await form.find('.date-stub').setValue('2096-07-01')
    await form.findAll('input').at(-1)!.setValue('Synthetic correction')
    await form.trigger('submit'); await flushPromises()
    expect(mocks.cancel).toHaveBeenCalledWith(1, 33, expect.objectContaining({ target_date: '2096-07-01', reason: 'Synthetic correction' }))
  })

  it('discards delayed responses after switching supplier', async () => {
    let resolveOld!: (value: unknown) => void
    mocks.keys.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
    render(); await flushPromises()
    mocks.keys.mockResolvedValue([])
    useSupplierStore().currentSupplierId = 2
    await flushPromises()
    resolveOld([version]); await flushPromises()
    expect(wrapper!.text()).not.toContain('Synthetic measured rooms')
    expect(mocks.keys).toHaveBeenLastCalledWith(2)
  })

  it('keeps history readable without exposing write controls', async () => {
    mocks.write = false; mocks.batches.mockResolvedValue([batch])
    render(); await flushPromises()
    expect(wrapper!.text()).toContain('Synthetic actual use')
    expect(wrapper!.findAll('button').some(item => item.text() === 'jointAllocations.keyEditor')).toBe(false)
    await button('detail').trigger('click'); await flushPromises()
    expect(wrapper!.findAll('form')).toHaveLength(1)
  })
})


describe('Independent J5 review reproductions', () => {
  it('preserves entered exact evidence and amounts when another source is selected', async () => {
    mocks.candidates.mockResolvedValue({ items: [candidate, { ...candidate, id: 89 }], next_before_id: null })
    render(); await flushPromises()
    await wrapper!.find('select').setValue('3')
    await wrapper!.find('.date-stub').setValue('2096-06-30')
    await searchSources(); await flushPromises()
    await wrapper!.findAll('input[type="checkbox"]')[0]!.setValue(true); await flushPromises()
    await section('exactCells').find('textarea').setValue('Measured source A')
    await section('exactCells').findAll('input')[0]!.setValue('1.23')
    await wrapper!.findAll('input[type="checkbox"]')[1]!.setValue(true); await flushPromises()
    expect((section('exactCells').find('textarea').element as HTMLTextAreaElement).value).toBe('Measured source A')
    expect((section('exactCells').findAll('input')[0]!.element as HTMLInputElement).value).toBe('1.23')
    await wrapper!.findAll('input[type="checkbox"]')[0]!.setValue(false); await flushPromises()
    await wrapper!.findAll('input[type="checkbox"]')[0]!.setValue(true); await flushPromises()
    expect((section('exactCells').find('textarea').element as HTMLTextAreaElement).value).toBe('')
  })

  it('shows native exact-total error amounts as complete decimals', async () => {
    mocks.preview.mockRejectedValue({ response: { data: { error: { code: 'allocation_exact_total_mismatch', message: 'Exact total mismatch',
      source_line_id: 88, amount_field: 'amount', original: '123.45', preview: '123.44' } } } })
    render(); await flushPromises()
    await wrapper!.find('select').setValue('3')
    await wrapper!.find('.date-stub').setValue('2096-06-30')
    await searchSources(); await flushPromises()
    await wrapper!.find('input[type="checkbox"]').setValue(true); await flushPromises()
    await button('preview').trigger('click'); await flushPromises()
    expect(wrapper!.find('[role="alert"]').text()).toContain('123.45')
    expect(wrapper!.find('[role="alert"]').text()).toContain('123.44')
  })

  it('does not preview a previous target date when the visible DateInput is invalid', async () => {
    render(true); await flushPromises()
    await wrapper!.find('select').setValue('3')
    const date = wrapper!.findAllComponents(DateInput)[0]!.find('input[type="text"]')
    await date.setValue('06/30/2096'); await date.trigger('blur'); await flushPromises()
    await searchSources(); await flushPromises()
    await wrapper!.find('input[type="checkbox"]').setValue(true); await flushPromises()
    await date.setValue('invalid'); await date.trigger('blur'); await flushPromises()
    expect((date.element as HTMLInputElement).validity.valid).toBe(false)
    await button('preview').trigger('click'); await flushPromises()
    expect(mocks.preview).not.toHaveBeenCalled()
  })

  it('discards a delayed preview after supplier change', async () => {
    let resolvePreview!: (value: unknown) => void
    mocks.preview.mockImplementationOnce(() => new Promise(resolve => { resolvePreview = resolve }))
    render(); await flushPromises()
    await wrapper!.find('select').setValue('3')
    await wrapper!.find('.date-stub').setValue('2096-06-30')
    await searchSources(); await flushPromises()
    await wrapper!.find('input[type="checkbox"]').setValue(true); await flushPromises()
    await button('preview').trigger('click'); await flushPromises()
    useSupplierStore().currentSupplierId = 2; await flushPromises()
    resolvePreview(preview); await flushPromises()
    expect(wrapper!.text()).not.toContain('jointAllocations.previewHelp')
    expect(wrapper!.findAll('input[type="checkbox"]')).toHaveLength(0)
  })
})


describe('Native form validity at every J5 action', () => {
  it('blocks source search for invalid visible target dates', async () => {
    render(true); await flushPromises()
    const date = wrapper!.findAllComponents(DateInput)[0]!.find('input[type="text"]')
    await date.setValue('06/30/2096'); await date.trigger('blur')
    await date.setValue('invalid'); await date.trigger('blur')
    await searchSources(); await flushPromises()
    expect(mocks.candidates).not.toHaveBeenCalled()
  })

  it('blocks cancellation when its visible date is invalid despite the last valid model', async () => {
    mocks.batches.mockResolvedValue([batch])
    render(true); await flushPromises()
    await button('detail').trigger('click'); await flushPromises()
    const form = wrapper!.findAll('form').at(-1)!
    const date = form.find('input[type="text"]')
    await date.setValue('07/01/2096'); await date.trigger('blur')
    await date.setValue('invalid'); await date.trigger('blur')
    await form.findAll('input').at(-1)!.setValue('Synthetic cancellation evidence')
    await form.trigger('submit'); await flushPromises()
    expect(mocks.cancel).not.toHaveBeenCalled()
  })

  it('clears exact cells when the key version changes', async () => {
    mocks.keys.mockResolvedValue([version, { ...version, id: 4, version_no: 2 }])
    render(); await flushPromises()
    await wrapper!.find('select').setValue('3')
    await wrapper!.find('.date-stub').setValue('2096-06-30')
    await searchSources(); await flushPromises()
    await wrapper!.find('input[type="checkbox"]').setValue(true); await flushPromises()
    await section('exactCells').find('textarea').setValue('Evidence for old version')
    await section('exactCells').findAll('input')[0]!.setValue('1.23')
    await wrapper!.find('select').setValue('4'); await flushPromises()
    expect((section('exactCells').find('textarea').element as HTMLTextAreaElement).value).toBe('')
    expect((section('exactCells').findAll('input')[0]!.element as HTMLInputElement).value).toBe('')
  })
})


it('blocks apply for an invalid visible date even when an older preview exists', async () => {
  mocks.keys.mockResolvedValue([{ ...version, allocation_mode: 'weighted' }])
  render(true); await flushPromises()
  await wrapper!.find('select').setValue('3')
  const date = wrapper!.findAllComponents(DateInput)[0]!.find('input[type="text"]')
  await date.setValue('06/30/2096'); await date.trigger('blur')
  await searchSources(); await flushPromises()
  await wrapper!.find('input[type="checkbox"]').setValue(true); await flushPromises()
  await wrapper!.findAll('textarea').at(-1)!.setValue('Synthetic allocation reason')
  await button('preview').trigger('click'); await flushPromises()
  expect(wrapper!.text()).toContain('jointAllocations.previewHelp')
  await date.setValue('invalid'); await date.trigger('blur')
  await button('apply').trigger('click'); await flushPromises()
  expect(mocks.apply).not.toHaveBeenCalled()
})
