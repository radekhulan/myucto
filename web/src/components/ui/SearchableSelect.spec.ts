import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

const options = [
  { value: '521', label: '521', secondary: 'Mzdové náklady' },
  { value: '522', label: '522', secondary: 'Příjmy společníků' },
  { value: '523', label: '523', secondary: 'Odměny orgánů' },
]

afterEach(() => {
  document.body.innerHTML = ''
})

describe('SearchableSelect', () => {
  it('zachová výchozí primární vzhled a volitelné payroll vlastnosti přidá jen na vyžádání', () => {
    const defaultWrapper = mount(SearchableSelect, {
      props: { modelValue: null, options },
    })
    const defaultInput = defaultWrapper.get('input[role="combobox"]')
    expect(defaultInput.classes()).toContain('focus:border-primary-500')
    expect(defaultInput.classes()).not.toContain('focus:border-payroll-500')
    expect(defaultInput.attributes('aria-invalid')).toBeUndefined()
    defaultWrapper.unmount()

    const payrollWrapper = mount(SearchableSelect, {
      props: {
        modelValue: '521',
        options,
        accent: 'payroll',
        invalid: true,
        required: true,
        ariaLabel: 'Mzda — Má dáti',
        inputId: 'payroll-debit-account',
        clearLabel: 'Zrušit účet',
        inputClass: 'font-mono',
      },
    })
    const payrollInput = payrollWrapper.get('input[role="combobox"]')
    expect(payrollInput.classes()).toContain('focus:border-payroll-500')
    expect(payrollInput.classes()).toContain('font-mono')
    expect(payrollInput.attributes('aria-invalid')).toBe('true')
    expect(payrollInput.attributes('aria-required')).toBe('true')
    expect(payrollInput.attributes('required')).toBeDefined()
    expect(payrollInput.attributes('aria-label')).toBe('Mzda — Má dáti')
    expect(payrollInput.attributes('id')).toBe('payroll-debit-account')
    expect(payrollWrapper.get('button').attributes('aria-label')).toBe('Zrušit účet')
    payrollWrapper.unmount()
  })

  it('propojí aktivní položku přes ARIA a podporuje Home, End, Enter i Tab', async () => {
    const wrapper = mount(SearchableSelect, {
      attachTo: document.body,
      props: { modelValue: null, options },
    })
    const input = wrapper.get('input[role="combobox"]')

    await input.trigger('focus')
    const listboxId = input.attributes('aria-controls')
    expect(listboxId).toBeTruthy()
    expect(document.getElementById(listboxId!)).not.toBeNull()

    await input.trigger('keydown', { key: 'End' })
    expect(input.attributes('aria-activedescendant')).toBe(`${listboxId}-option-2`)
    await input.trigger('keydown', { key: 'Home' })
    expect(input.attributes('aria-activedescendant')).toBe(`${listboxId}-option-0`)
    await input.trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['521'])

    await wrapper.setProps({ modelValue: '521' })
    await input.trigger('focus')
    await input.setValue('neuložený text')
    await input.trigger('keydown', { key: 'Tab' })
    expect(input.attributes('aria-expanded')).toBe('false')
    expect((input.element as HTMLInputElement).value).toBe('521')

    wrapper.unmount()
  })
})
