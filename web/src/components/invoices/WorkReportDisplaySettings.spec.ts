import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { describe, expect, it } from 'vitest'
import WorkReportDisplaySettings from './WorkReportDisplaySettings.vue'

function mountSettings(props: Record<string, unknown> = {}) {
  return mount(WorkReportDisplaySettings, {
    props,
    global: { plugins: [createI18n({ legacy: false, locale: 'cs', missingWarn: false, fallbackWarn: false, messages: { cs: {} } })] },
  })
}

describe('Zobrazení výkazu práce', () => {
  it('převezme výchozí nastavení a při vypnutí dědění vytvoří vlastní výjimku', async () => {
    const wrapper = mountSettings({ modelValue: null, inheritable: true, defaults: { compact: true, rate: false, amount: false } })
    expect(wrapper.get<HTMLInputElement>('[data-test="work-report-column-rate"]').element.checked).toBe(false)
    expect(wrapper.get<HTMLInputElement>('[data-test="work-report-compact"]').element.disabled).toBe(true)
    await wrapper.get('[data-test="work-report-inherit"]').setValue(false)
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([{ compact: true, date: true, hours: true, rate: false, amount: false }])
  })

  it('umožní samostatně změnit sloupec a obnovit dědění', async () => {
    const wrapper = mountSettings({ modelValue: { rate: false }, inheritable: true, defaults: { compact: true } })
    await wrapper.get('[data-test="work-report-column-amount"]').setValue(false)
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([{ compact: true, date: true, hours: true, rate: false, amount: false }])
    await wrapper.get('[data-test="work-report-inherit"]').setValue(true)
    expect(wrapper.emitted('update:modelValue')?.[1]).toEqual([null])
  })

  it('v nastavení firmy edituje přímo výchozí podobu bez přepínače dědění', async () => {
    const wrapper = mountSettings()
    expect(wrapper.find('[data-test="work-report-inherit"]').exists()).toBe(false)
    await wrapper.get('[data-test="work-report-compact"]').setValue(true)
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([{ compact: true, date: true, hours: true, rate: true, amount: true }])
  })
})
