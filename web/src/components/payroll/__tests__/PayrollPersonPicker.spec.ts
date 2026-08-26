import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import PayrollPersonPicker from '@/components/payroll/PayrollPersonPicker.vue'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

function options(count: number) {
  return Array.from({ length: count }, (_, index) => ({
    value: index + 1,
    label: `Zaměstnanec ${index + 1}`,
  }))
}

describe('PayrollPersonPicker', () => {
  it('pro nejvýše patnáct lidí zachová rychlé záložky', async () => {
    const wrapper = mount(PayrollPersonPicker, {
      props: { modelValue: 1, options: options(15), selectorLabel: 'Zaměstnanec' },
    })

    expect(wrapper.find('[data-test="payroll-person-picker-tabs"]').exists()).toBe(true)
    expect(wrapper.findAll('button')).toHaveLength(15)
    expect(wrapper.findComponent(PayrollPersonSearchSelect).exists()).toBe(false)

    await wrapper.findAll('button')[14]!.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([15])
  })

  it('od přesně šestnácti lidí použije hledání místo pásu záložek', () => {
    const wrapper = mount(PayrollPersonPicker, {
      props: { modelValue: 1, options: options(16), selectorLabel: 'Zaměstnanec' },
    })

    expect(wrapper.find('[data-test="payroll-person-picker-search"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="payroll-person-picker-tabs"]').exists()).toBe(false)
    expect(wrapper.findComponent(PayrollPersonSearchSelect).exists()).toBe(true)
    expect(wrapper.find('[role="combobox"]').exists()).toBe(true)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('u pěti set lidí po otevření nabídne jen omezenou hledatelnou množinu', async () => {
    const wrapper = mount(PayrollPersonPicker, {
      props: { modelValue: 1, options: options(500), selectorLabel: 'Zaměstnanec' },
    })

    await wrapper.get('[role="combobox"]').trigger('focus')

    expect(wrapper.findAll('[role="option"]')).toHaveLength(25)
    expect(wrapper.find('[data-test="searchable-select-truncated"]').exists()).toBe(true)
    expect(wrapper.findAll('nav button')).toHaveLength(0)
  })
})
