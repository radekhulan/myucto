import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { describe, expect, it } from 'vitest'
import AccrualSpreadNote from '../AccrualSpreadNote.vue'
import AccrualSpreadToggle from '../AccrualSpreadToggle.vue'
import type { StatementAccrualSpread } from '@/api/accounting'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })
const format = (value: number) => `${value.toFixed(2)} Kč`
const spread: StatementAccrualSpread = {
  applied: true, skipped_reason: null, prepaid_expense: 6100, deferred_revenue: 24300, documents: 2, prev: null,
}

describe('průběžné časové rozlišení ve výkazech', () => {
  it('poznámka uvádí částky 381/384 a že úprava není v deníku', () => {
    const wrapper = mount(AccrualSpreadNote, { props: { spread, format }, global: { plugins: [i18n] } })
    const text = wrapper.get('[data-test="accrual-spread-note"]').text()
    expect(text).toContain('6100.00 Kč')
    expect(text).toContain('24300.00 Kč')
    expect(text).toContain('není zaúčtovaná v deníku')
  })

  it('výkaz za hodnotu dimenze hlásí, že volbu neuplatnil', () => {
    const wrapper = mount(AccrualSpreadNote, {
      props: { spread: { ...spread, applied: false, skipped_reason: 'dimension' }, format },
      global: { plugins: [i18n] },
    })
    expect(wrapper.find('[data-test="accrual-spread-note"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="accrual-spread-skipped"]').text()).toContain('dimenze')
  })

  it('přepínač emituje nový stav', async () => {
    const wrapper = mount(AccrualSpreadToggle, { props: { modelValue: false }, global: { plugins: [i18n] } })
    await wrapper.get('input').setValue(true)
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true])
  })
})
