import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollDeferredIncome } from '@/api/payrollDeferredIncome'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  save: vi.fn(),
  remove: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payrollDeferredIncome', () => ({
  payrollDeferredIncomeApi: { list: m.list, save: m.save, remove: m.remove },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key,
    locale: { value: 'cs' },
  }),
}))

import EmploymentDeferredIncomePanel from '@/pages/payroll/EmploymentDeferredIncomePanel.vue'

function item(overrides: Partial<PayrollDeferredIncome> = {}): PayrollDeferredIncome {
  return {
    id: 5,
    employment_id: 12,
    period_start: '2026-07-01',
    deferred_type: '1',
    note: null,
    row_version: 1,
    updated_at: '2026-07-20 10:00:00',
    ...overrides,
  }
}

function mountPanel(canWrite = true) {
  return mount(EmploymentDeferredIncomePanel, {
    props: { employmentId: 12, endDate: '2026-06-30', canWrite },
  })
}

describe('EmploymentDeferredIncomePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.list.mockResolvedValue({ items: [], supported_types: ['1'] })
  })

  it('potvrdí odložený příjem za měsíc zúčtování po skončení vztahu', async () => {
    m.save.mockResolvedValue(item({ note: 'Doplatek odměny' }))
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="deferred-income-empty"]').exists()).toBe(true)

    await wrapper.find('[data-test="deferred-income-add"]').trigger('click')
    expect((wrapper.find('[data-test="deferred-income-period"]').element as HTMLInputElement).value).toBe('2026-07')
    expect(wrapper.findAll('[data-test="deferred-income-type"] option').map(option => option.attributes('value')))
      .toEqual(['1'])
    await wrapper.find('[data-test="deferred-income-note"]').setValue('Doplatek odměny')
    await wrapper.find('[data-test="deferred-income-form"]').trigger('submit')
    await flushPromises()

    expect(m.save).toHaveBeenCalledWith(12, '2026-07', { deferred_type: '1', note: 'Doplatek odměny' })
    expect(wrapper.findAll('[data-test="deferred-income-item"]')).toHaveLength(1)
    expect(m.success).toHaveBeenCalled()
  })

  it('neuloží měsíc, ve kterém vztah ještě trval', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.find('[data-test="deferred-income-add"]').trigger('click')
    await wrapper.find('[data-test="deferred-income-period"]').setValue('2026-06')
    await wrapper.find('[data-test="deferred-income-form"]').trigger('submit')
    await flushPromises()

    expect(m.save).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="deferred-income-invalid"]').text()).toContain('2026-07')
  })

  it('nabídne typy podle serveru a roční zúčtování po skončení jen za leden až březen', async () => {
    m.list.mockResolvedValue({ items: [], supported_types: ['1', '4'] })
    m.save.mockResolvedValue(item({ period_start: '2027-02-01', deferred_type: '4' }))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.find('[data-test="deferred-income-add"]').trigger('click')
    expect(wrapper.findAll('[data-test="deferred-income-type"] option').map(option => option.attributes('value')))
      .toEqual(['1', '4'])

    await wrapper.find('[data-test="deferred-income-type"]').setValue('4')
    expect(wrapper.find('[data-test="deferred-income-type-4-hint"]').exists()).toBe(true)
    await wrapper.find('[data-test="deferred-income-period"]').setValue('2026-07')
    await wrapper.find('[data-test="deferred-income-form"]').trigger('submit')
    await flushPromises()
    expect(m.save).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="deferred-income-invalid"]').text())
      .toBe('payroll.people.deferred_income.period_annual_invalid')

    await wrapper.find('[data-test="deferred-income-period"]').setValue('2027-02')
    await wrapper.find('[data-test="deferred-income-form"]').trigger('submit')
    await flushPromises()
    expect(m.save).toHaveBeenCalledWith(12, '2027-02', { deferred_type: '4', note: null })
    expect(wrapper.find('[data-test="deferred-income-item"]').text()).toContain('payroll.people.deferred_income.type_4')
  })

  it('typ 5 nese stejné omezení na leden až březen jako typ 4', async () => {
    m.list.mockResolvedValue({ items: [], supported_types: ['1', '4', '5'] })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.find('[data-test="deferred-income-add"]').trigger('click')
    expect(wrapper.findAll('[data-test="deferred-income-type"] option').map(option => option.attributes('value')))
      .toEqual(['1', '4', '5'])
    await wrapper.find('[data-test="deferred-income-type"]').setValue('5')
    expect(wrapper.find('[data-test="deferred-income-type-5-hint"]').exists()).toBe(true)
    await wrapper.find('[data-test="deferred-income-period"]').setValue('2026-07')
    await wrapper.find('[data-test="deferred-income-form"]').trigger('submit')
    await flushPromises()
    expect(m.save).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="deferred-income-invalid"]').text())
      .toBe('payroll.people.deferred_income.period_annual_invalid')
  })

  it('zruší potvrzení a bez práva zápisu nenabízí úpravy', async () => {
    m.list.mockResolvedValue({ items: [item()], supported_types: ['1'] })
    m.remove.mockResolvedValue(true)
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.find('[data-test="deferred-income-remove"]').trigger('click')
    await flushPromises()
    expect(m.remove).toHaveBeenCalledWith(12, '2026-07')
    expect(wrapper.findAll('[data-test="deferred-income-item"]')).toHaveLength(0)

    const readOnly = mountPanel(false)
    await flushPromises()
    expect(readOnly.find('[data-test="deferred-income-add"]').exists()).toBe(false)
    expect(readOnly.find('[data-test="deferred-income-remove"]').exists()).toBe(false)
  })
})
