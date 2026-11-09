import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import NumberTemplateInput from '@/components/ui/NumberTemplateInput.vue'

const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs: { number_template: cs.number_template } } })

describe('NumberTemplateInput', () => {
  it('při psaní převede závorky a vyšle složené', async () => {
    const w = mount(NumberTemplateInput, { props: { modelValue: '' }, global: { plugins: [i18n] } })
    const input = w.find('input')
    ;(input.element as HTMLInputElement).value = '(YY)[CCCC]'
    await input.trigger('input')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['{YY}{CCCC}'])
    expect((input.element as HTMLInputElement).value).toBe('{YY}{CCCC}')
  })
  it('{YYx} označí pole chybným a ukáže hlášku', () => {
    const w = mount(NumberTemplateInput, { props: { modelValue: '{YYx}{CCCC}' }, global: { plugins: [i18n] } })
    expect(w.find('input').attributes('aria-invalid')).toBe('true')
    expect(w.find('[role=alert]').text()).toContain('neznámý zástupný symbol')
  })
  it('platný tvar ukáže náhled', () => {
    const w = mount(NumberTemplateInput, { props: { modelValue: '{YYYY}{CCC}' }, global: { plugins: [i18n] } })
    expect(w.find('input').attributes('aria-invalid')).toBe('false')
    expect(w.text()).toContain(`${new Date().getFullYear()}001`)
  })
})
