import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))

import EnvironmentSwitch from '@/components/ui/EnvironmentSwitch.vue'

function mountSwitch(props: Record<string, unknown> = {}) {
  return mount(EnvironmentSwitch, { props: { modelValue: 'production', ...props } })
}

const production = '[data-test="environment-switch-production"]'
const test = '[data-test="environment-switch-test"]'

describe('EnvironmentSwitch', () => {
  it('nabídne obě prostředí viditelně vedle sebe, ne v rozbalovacím seznamu', () => {
    const wrapper = mountSwitch()

    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.find('[role="radiogroup"]').exists()).toBe(true)
    expect(wrapper.findAll('[role="radio"]')).toHaveLength(2)
    expect(wrapper.get(production).attributes('aria-checked')).toBe('true')
    expect(wrapper.get(test).attributes('aria-checked')).toBe('false')
  })

  it('kliknutím na testovací prostředí emituje hodnotu', async () => {
    const wrapper = mountSwitch()

    await wrapper.get(test).trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([['test']])
  })

  it('neemituje znovu, když je volba už vybraná', async () => {
    const wrapper = mountSwitch()

    await wrapper.get(production).trigger('click')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  /**
   * Uživatel si spletl přihlašovací údaje, protože testovací prostředí vypadalo
   * stejně jako ostré. Varovné zbarvení proto není kosmetika, ale to jediné,
   * co v hlavičce panelu prostředí odliší.
   */
  it('zvýrazní testovací prostředí varovně, ostré nikoli', async () => {
    const wrapper = mountSwitch({ modelValue: 'test' })

    const group = wrapper.get('[role="radiogroup"]')
    expect(group.attributes('data-environment')).toBe('test')
    expect(group.classes().join(' ')).toContain('warning')
    expect(wrapper.get(test).classes().join(' ')).toContain('bg-warning-500')

    await wrapper.setProps({ modelValue: 'production' })

    expect(wrapper.get('[role="radiogroup"]').classes().join(' ')).not.toContain('warning')
    expect(wrapper.get(production).classes().join(' ')).toContain('bg-success-600')
  })

  it('přepne šipkou a drží roving tabindex', async () => {
    const wrapper = mountSwitch()

    expect(wrapper.get(production).attributes('tabindex')).toBe('0')
    expect(wrapper.get(test).attributes('tabindex')).toBe('-1')

    await wrapper.get('[role="radiogroup"]').trigger('keydown', { key: 'ArrowRight' })

    expect(wrapper.emitted('update:modelValue')).toEqual([['test']])
  })

  it('zakázaný přepínač nepřepíná ani klikem, ani klávesnicí', async () => {
    const wrapper = mountSwitch({ disabled: true })

    await wrapper.get(test).trigger('click')
    await wrapper.get('[role="radiogroup"]').trigger('keydown', { key: 'ArrowRight' })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.get(test).attributes('disabled')).toBeDefined()
  })

  it('kompaktní varianta se vejde do hlavičky panelu', () => {
    const wrapper = mountSwitch({ size: 'sm' })

    expect(wrapper.get(production).classes()).toContain('h-7')
  })

  it('respektuje vlastní popisky voleb', () => {
    const wrapper = mountSwitch({ productionLabel: 'Ostrá schránka', testLabel: 'Testovací schránka' })

    expect(wrapper.get(production).text()).toBe('Ostrá schránka')
    expect(wrapper.get(test).text()).toBe('Testovací schránka')
  })
})
