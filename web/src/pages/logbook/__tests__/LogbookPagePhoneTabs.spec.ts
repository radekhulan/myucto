import { describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({ replace: vi.fn().mockResolvedValue(undefined) }))
const phoneRef = ref(false)

vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: { tab: 'trips' } }),
  useRouter: () => ({ replace: m.replace }),
}))
vi.mock('../TripsTab.vue', () => ({ default: { name: 'TripsTab', template: '<div />' } }))
vi.mock('../CarsTab.vue', () => ({ default: { name: 'CarsTab', template: '<div />' } }))
vi.mock('../FuelingsTab.vue', () => ({ default: { name: 'FuelingsTab', template: '<div />' } }))
vi.mock('../CategoriesTab.vue', () => ({ default: { name: 'CategoriesTab', template: '<div />' } }))
vi.mock('../TemplatesTab.vue', () => ({ default: { name: 'TemplatesTab', template: '<div />' } }))
vi.mock('../SummariesTab.vue', () => ({ default: { name: 'SummariesTab', template: '<div />' } }))

import LogbookPage from '../LogbookPage.vue'

describe('Kniha jízd: záložky na telefonu', () => {
  it('mimo telefon ukáže pruh šesti záložek', async () => {
    phoneRef.value = false
    const wrapper = shallowMount(LogbookPage)
    await flushPromises()
    expect(wrapper.find('[data-test="logbook-phone-tabs"]').exists()).toBe(false)
    expect(wrapper.findAll('button')).toHaveLength(6)
  })

  it('na telefonu má Jízdy, Tankování a Auta nahoře a zbytek ve výběru Více', async () => {
    phoneRef.value = true
    const wrapper = shallowMount(LogbookPage)
    await flushPromises()
    const primary = wrapper.findAll('[role="tab"]').map(b => b.text())
    expect(primary).toEqual(['logbook.tab_trips', 'logbook.tab_fuel', 'logbook.tab_cars'])
    const select = wrapper.get('[data-test="logbook-phone-more"]')
    expect(select.findAll('option').map(o => o.attributes('value'))).toEqual(['', 'templates', 'categories', 'summary'])
    ;(select.element as HTMLSelectElement).value = 'summary'
    await select.trigger('change')
    await flushPromises()
    expect(m.replace).toHaveBeenLastCalledWith({ query: { tab: 'summary' } })
    expect((wrapper.get('[data-test="logbook-phone-more"]').element as HTMLSelectElement).value).toBe('summary')
    expect(wrapper.findAll('[role="tab"]').every(b => b.attributes('aria-selected') === 'false')).toBe(true)
  })
})
