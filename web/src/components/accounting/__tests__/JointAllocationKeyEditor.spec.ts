import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
import type { DimensionType, DimensionValue } from '@/api/dimensions'
import type { JointVersion, RevenueProposal } from '@/api/jointAllocations'
const mocks = vi.hoisted(() => ({ revenueProposal: vi.fn() }))
vi.mock('@/api/client', () => ({ api: {} }))
vi.mock('@/api/jointAllocations', async (importOriginal) => ({ ...(await importOriginal<typeof import('@/api/jointAllocations')>()), jointAllocationsApi: mocks }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, string>) => params ? `${key} ${Object.values(params).join(' ')}` : key, locale: { value: 'en' } }) }))
import JointAllocationKeyEditor from '../JointAllocationKeyEditor.vue'
import DateInput from '@/components/ui/DateInput.vue'

describe('Immutable joint key editor', () => {
  it('requires explicit system choices and copies a complete reactive version into a new key', async () => {
    const types = [
      { id: 1, name: 'Activity', system_key: 'activity', is_active: true },
      { id: 2, name: 'Kind', system_key: 'activity_kind', is_active: true },
      { id: 3, name: 'Tax', system_key: 'tax_regime', is_active: true },
      { id: 9, name: 'Rooms', system_key: null, is_active: true },
    ] as DimensionType[]
    const values = [{ id: 11, type_id: 1, name: 'Synthetic main', is_active: true }, { id: 21, type_id: 2, name: 'Synthetic sport', is_active: true },
      { id: 31, type_id: 3, name: 'Synthetic tax', is_active: true }, { id: 91, type_id: 9, name: 'Synthetic room', is_active: true }] as DimensionValue[]
    const original = reactive({ id: 5, key_id: 2, name: 'Synthetic original', code: 'SOURCE', version_no: 1,
      valid_from: '2096-01-01', valid_to: '2097-01-01', basis: 'fixed', explanation: 'Synthetic evidence', allocation_mode: 'explicit_amounts', cell_ids: [],
      cells: [{ activity: 11, activity_kind: 21, tax_regime: 31, weight: '1', additional_dimensions: { 9: 91 } }] }) as JointVersion
    const wrapper = mount(JointAllocationKeyEditor, { props: { types, values, versions: [original], busy: false, supplierId: 1 }, global: { stubs: { DateInput: true } } })
    const axisSelects = wrapper.findAll('select').slice(-3)
    expect(axisSelects.map(select => (select.element as HTMLSelectElement).value)).toEqual(['0', '0', '0'])
    wrapper.vm.copy(original)
    await wrapper.vm.$nextTick()
    await wrapper.find('input[pattern]').setValue('COPY')
    await wrapper.find('form').trigger('submit')
    const saved = wrapper.emitted('save')![0]![0] as { keyId: number; code: string; version: JointVersion }
    expect(saved.keyId).toBe(0)
    expect(saved.code).toBe('COPY')
    expect(saved.version.cells).toEqual(original.cells)
    expect(saved.version.allocation_mode).toBe('explicit_amounts')
    saved.version.cells[0]!.additional_dimensions![9] = 92
    expect(original.cells[0]!.additional_dimensions![9]).toBe(91)
  })
})


describe('Native key form validity', () => {
  it('does not emit a saved version with an invalid visible date', async () => {
    const wrapper = mount(JointAllocationKeyEditor, { props: { types: [], values: [], versions: [], busy: false, supplierId: 1 } })
    await wrapper.find('input[pattern]').setValue('COPY')
    await wrapper.find('input[maxlength="190"]').setValue('Synthetic key')
    await wrapper.find('textarea').setValue('Synthetic documented basis')
    const dates = wrapper.findAllComponents(DateInput)
    await dates[0]!.find('input[type="text"]').setValue('01/01/2096')
    const date = dates[1]!.find('input[type="text"]')
    await date.setValue('01/01/2097'); await date.trigger('blur')
    await date.setValue('invalid'); await date.trigger('blur')
    expect((date.element as HTMLInputElement).validity.valid).toBe(false)
    await wrapper.find('form').trigger('submit')
    expect(wrapper.emitted('save')).toBeUndefined()
    wrapper.unmount()
  })

  it('keeps an invalid advisory source date from blocking the key form', async () => {
    const wrapper = mount(JointAllocationKeyEditor, { props: { types: [], values: [], versions: [], busy: false, supplierId: 1 } })
    const advisory = wrapper.findAllComponents(DateInput)[2]!.find('input[type="text"]')
    await advisory.setValue('invalid'); await advisory.trigger('blur')
    expect((advisory.element as HTMLInputElement).validity.valid).toBe(false)
    expect(wrapper.find('form').element.contains(advisory.element)).toBe(false)
    wrapper.unmount()
  })

  it('never fetches the previous valid period when a visible source date is invalid', async () => {
    mocks.revenueProposal.mockReset()
    const wrapper = mount(JointAllocationKeyEditor, { props: { types: [], values: [], versions: [], busy: false, supplierId: 1 } })
    const dates = wrapper.findAllComponents(DateInput)
    await dates[2]!.find('input[type="text"]').setValue('01/01/2096')
    await dates[3]!.find('input[type="text"]').setValue('12/31/2096')
    const source = dates[2]!.find('input[type="text"]')
    await source.setValue('invalid'); await source.trigger('blur')
    await wrapper.find('[data-test="fetch-proposal"]').trigger('click')
    expect(mocks.revenueProposal).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})

describe('Revenue based proposal', () => {
  const types = [
    { id: 1, name: 'Activity', system_key: 'activity', is_active: true },
    { id: 2, name: 'Kind', system_key: 'activity_kind', is_active: true },
    { id: 3, name: 'Tax', system_key: 'tax_regime', is_active: true },
    { id: 9, name: 'Rooms', system_key: null, is_active: true },
  ] as DimensionType[]
  const values = [{ id: 11, type_id: 1, name: 'Synthetic main', is_active: true }, { id: 12, type_id: 1, name: 'Synthetic side', is_active: true },
    { id: 21, type_id: 2, name: 'Synthetic sport', is_active: true }, { id: 31, type_id: 3, name: 'Synthetic tax', is_active: true },
    { id: 91, type_id: 9, name: 'Synthetic room', is_active: true }] as DimensionValue[]
  const proposal = (over: Partial<RevenueProposal> = {}): RevenueProposal => ({ basis: 'revenue', date_from: '2096-01-01', date_to: '2096-12-31',
    net_revenue_cents: '123456789', source_lines: 3, unclassified_lines: 0, unclassified_amount_cents: '0',
    cells: [{ activity: 11, activity_kind: 21, tax_regime: 31, weight: '3' }, { activity: 12, activity_kind: 21, tax_regime: 31, weight: '1' }],
    can_propose: true, reason_codes: [], proposal_hash: 'synthetic-hash', verification_scope: 'posted_revenue_aggregates',
    requires_manual_confirmation: true, mutations_performed: false, ...over })
  const mountEditor = (props: Partial<{ supplierId: number; busy: boolean }> = {}) => mount(JointAllocationKeyEditor, { props: { types, values, versions: [], busy: false, supplierId: 1, ...props }, global: { stubs: { DateInput: true } } })
  const setDates = async (wrapper: VueWrapper<any>, from = '2096-01-01', to = '2096-12-31') => {
    const dates = wrapper.findAllComponents(DateInput)
    dates[2]!.vm.$emit('update:modelValue', from)
    dates[3]!.vm.$emit('update:modelValue', to)
    await nextTick()
  }
  const fetchBtn = (wrapper: VueWrapper<any>) => wrapper.find('[data-test="fetch-proposal"]')
  const applyBtn = (wrapper: VueWrapper<any>) => wrapper.find('[data-test="apply-proposal"]')
  const loadProposal = async (wrapper: VueWrapper<any>, result: RevenueProposal) => {
    mocks.revenueProposal.mockResolvedValueOnce(result)
    await setDates(wrapper)
    await fetchBtn(wrapper).trigger('click')
    await flushPromises()
  }
  const axisValues = (wrapper: VueWrapper<any>, cells: number) => wrapper.findAll('select').slice(-3 * cells).map(select => (select.element as HTMLSelectElement).value)
  beforeEach(() => mocks.revenueProposal.mockReset())

  it('guards invalid ranges and busy state before calling the API', async () => {
    const wrapper = mountEditor()
    expect(fetchBtn(wrapper).attributes('disabled')).toBeDefined()
    await setDates(wrapper, '2096-12-31', '2096-01-01')
    expect(fetchBtn(wrapper).attributes('disabled')).toBeDefined()
    await setDates(wrapper, '2096-02-30', '2096-03-01')
    expect(fetchBtn(wrapper).attributes('disabled')).toBeDefined()
    await setDates(wrapper)
    expect(fetchBtn(wrapper).attributes('disabled')).toBeUndefined()
    await wrapper.setProps({ busy: true })
    expect(fetchBtn(wrapper).attributes('disabled')).toBeDefined()
    expect(mocks.revenueProposal).not.toHaveBeenCalled()
  })

  it('loads a proposal for the current supplier without touching the form or saving', async () => {
    const wrapper = mountEditor({ supplierId: 7 })
    await loadProposal(wrapper, proposal({ reason_codes: ['no_revenue', 'something_new'] }))
    expect(mocks.revenueProposal).toHaveBeenCalledWith(7, { from: '2096-01-01', to: '2096-12-31' })
    const text = wrapper.find('[data-test="proposal-result"]').text()
    expect(text).toContain('jointAllocations.revenueProposal.reasons.no_revenue')
    expect(text).toContain('jointAllocations.revenueProposal.reasons.unknown')
    expect(text).toContain('1 234 567,89')
    expect(axisValues(wrapper, 1)).toEqual(['0', '0', '0'])
    expect((wrapper.findAll('select')[1]!.element as HTMLSelectElement).value).toBe('fixed')
    expect(wrapper.emitted('save')).toBeUndefined()
  })

  it('applies only on explicit action, preserves metadata and still requires the regular save', async () => {
    const wrapper = mountEditor()
    const dates = wrapper.findAllComponents(DateInput)
    dates[0]!.vm.$emit('update:modelValue', '2097-01-01')
    dates[1]!.vm.$emit('update:modelValue', '2098-01-01')
    await wrapper.find('input[pattern]').setValue('REV')
    await wrapper.find('input[maxlength="190"]').setValue('Synthetic key')
    await wrapper.find('textarea').setValue('Synthetic documented basis')
    await loadProposal(wrapper, proposal())
    expect(axisValues(wrapper, 1)).toEqual(['0', '0', '0'])
    await applyBtn(wrapper).trigger('click')
    expect(axisValues(wrapper, 2)).toEqual(['11', '21', '31', '12', '21', '31'])
    expect((wrapper.findAll('select')[1]!.element as HTMLSelectElement).value).toBe('revenue')
    expect(wrapper.emitted('save')).toBeUndefined()
    await wrapper.find('form').trigger('submit')
    const saved = wrapper.emitted('save')![0]![0] as { version: { basis: string; explanation: string; valid_from: string; valid_to: string; cells: unknown[] } }
    expect(saved.version.basis).toBe('revenue')
    expect(saved.version.explanation).toBe('Synthetic documented basis')
    expect([saved.version.valid_from, saved.version.valid_to]).toEqual(['2097-01-01', '2098-01-01'])
    expect(saved.version.cells).toEqual([
      { activity: 11, activity_kind: 21, tax_regime: 31, weight: '3', additional_dimensions: {} },
      { activity: 12, activity_kind: 21, tax_regime: 31, weight: '1', additional_dimensions: {} },
    ])
  })

  it('refuses to apply a proposal the server marked unusable', async () => {
    const wrapper = mountEditor()
    await loadProposal(wrapper, proposal({ can_propose: false, cells: [], reason_codes: ['unclassified_revenue'] }))
    expect(applyBtn(wrapper).attributes('disabled')).toBeDefined()
    await applyBtn(wrapper).trigger('click')
    expect(axisValues(wrapper, 1)).toEqual(['0', '0', '0'])
    expect((wrapper.findAll('select')[1]!.element as HTMLSelectElement).value).toBe('fixed')
  })

  it('refuses to apply into explicit amounts or when additional axes are selected', async () => {
    const wrapper = mountEditor()
    await loadProposal(wrapper, proposal())
    expect(applyBtn(wrapper).attributes('disabled')).toBeUndefined()
    await wrapper.find('input[type="checkbox"]').setValue(true)
    expect(applyBtn(wrapper).attributes('disabled')).toBeDefined()
    await wrapper.find('input[type="checkbox"]').setValue(false)
    expect(applyBtn(wrapper).attributes('disabled')).toBeUndefined()
    await wrapper.findAll('select')[2]!.setValue('explicit_amounts')
    expect(applyBtn(wrapper).attributes('disabled')).toBeDefined()
  })

  it('drops a pending result when the supplier changes', async () => {
    let resolve!: (value: RevenueProposal) => void
    mocks.revenueProposal.mockReturnValueOnce(new Promise<RevenueProposal>(done => { resolve = done }))
    const wrapper = mountEditor()
    await setDates(wrapper)
    await fetchBtn(wrapper).trigger('click')
    await wrapper.setProps({ supplierId: 2 })
    resolve(proposal())
    await flushPromises()
    expect(wrapper.find('[data-test="proposal-result"]').exists()).toBe(false)
    expect(fetchBtn(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('drops a pending result when the source range changes and keeps the newer request', async () => {
    let resolveOld!: (value: RevenueProposal) => void
    mocks.revenueProposal.mockReturnValueOnce(new Promise<RevenueProposal>(done => { resolveOld = done }))
    const wrapper = mountEditor()
    await setDates(wrapper)
    await fetchBtn(wrapper).trigger('click')
    await setDates(wrapper, '2096-01-01', '2096-06-30')
    mocks.revenueProposal.mockResolvedValueOnce(proposal({ date_to: '2096-06-30', source_lines: 5 }))
    await fetchBtn(wrapper).trigger('click')
    await flushPromises()
    resolveOld(proposal())
    await flushPromises()
    const text = wrapper.find('[data-test="proposal-result"]').text()
    expect(text).toContain('5')
    expect(mocks.revenueProposal).toHaveBeenCalledTimes(2)
  })

  it('rejects a response for a different range than requested', async () => {
    const wrapper = mountEditor()
    await loadProposal(wrapper, proposal({ date_to: '2096-11-30' }))
    expect(wrapper.find('[data-test="proposal-result"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('jointAllocations.revenueProposal.errors.failed')
  })

  it('reports a rejected request and ignores a late result after unmount', async () => {
    mocks.revenueProposal.mockRejectedValueOnce({ response: { status: 422 } })
    const wrapper = mountEditor()
    await setDates(wrapper)
    await fetchBtn(wrapper).trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('jointAllocations.revenueProposal.errors.rejected')
    let resolve!: (value: RevenueProposal) => void
    mocks.revenueProposal.mockReturnValueOnce(new Promise<RevenueProposal>(done => { resolve = done }))
    await setDates(wrapper, '2096-01-01', '2096-02-01')
    await fetchBtn(wrapper).trigger('click')
    wrapper.unmount()
    resolve(proposal({ date_to: '2096-02-01' }))
    await flushPromises()
    expect(wrapper.emitted('save')).toBeUndefined()
  })
})
