import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import VendorPicker from '../VendorPicker.vue'

const mocks = vi.hoisted(() => ({ list: vi.fn(), get: vi.fn() }))
vi.mock('@/api/clients', () => ({ clientsApi: mocks }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

describe('VendorPicker counterparty roles', () => {
  beforeEach(() => { vi.resetAllMocks() })

  it('does not restore opposite-role results when an older search finishes after clearing', async () => {
    let finishSearch!: (value: { data: unknown[] }) => void
    mocks.list.mockImplementationOnce(() => new Promise(resolve => { finishSearch = resolve }))
      .mockResolvedValueOnce({ data: [] })
    const wrapper = mount(VendorPicker, { props: { modelValue: null } })
    const select = wrapper.getComponent({ name: 'SearchableSelect' })
    select.vm.$emit('search', 'Synthetic')
    select.vm.$emit('search', '')
    await flushPromises()
    finishSearch({ data: [{ id: 7, company_name: 'Synthetic customer', is_customer: true, is_vendor: false }] })
    await flushPromises()
    expect(select.props('options')).toEqual([])
  })

  it('keeps the default list vendor-only but offers customers while typing', async () => {
    const customer = { id: 7, company_name: 'Synthetic customer', is_customer: true, is_vendor: false }
    mocks.list.mockImplementation(async ({ role }) => ({ data: role === 'vendors' ? [] : [customer] }))
    const wrapper = mount(VendorPicker, { props: { modelValue: null } })
    const select = wrapper.getComponent({ name: 'SearchableSelect' })
    select.vm.$emit('search', '   ')
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith({ q: undefined, role: 'vendors', archived: false, per_page: 50 })
    expect(select.props('options')).toEqual([])

    select.vm.$emit('search', ' Synthetic ')
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith({ q: 'Synthetic', role: 'all', archived: false, per_page: 50 })
    expect(select.props('options')).toEqual([{ value: 7, label: customer.company_name, secondary: undefined }])
    select.vm.$emit('update:modelValue', 7)
    expect(wrapper.emitted('selected')?.at(-1)).toEqual([customer])
    expect(customer.is_vendor).toBe(false)

    select.vm.$emit('search', '')
    await flushPromises()
    expect(select.props('options')).toEqual([])
  })
})
