import { expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

// Telefon: stav a řazení opakovaných faktur jsou za tlačítkem „Filtry"; hledání
// zůstává vidět. Jen `max-md:` třídy, desktop beze změny.

vi.mock('@/api/recurring', () => ({ recurringApi: { list: async () => ({ data: [], meta: { total: 0, page: 1, pages: 1 } }) } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: {} }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useRowLink', () => ({ useRowLink: () => vi.fn() }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', async () => {
  const { ref } = await import('vue')
  return { useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }
})

import RecurringList from '../RecurringList.vue'

it('stav a řazení se na telefonu otevřou tlačítkem Filtry', async () => {
  const wrapper = shallowMount(RecurringList)
  await flushPromises()
  const toggle = wrapper.find('[data-test="recurring-filters-toggle"]')
  expect(toggle.classes()).toEqual(expect.arrayContaining(['hidden', 'max-md:inline-flex']))
  expect(wrapper.find('[data-test="recurring-status-filter"]').classes()).toContain('max-md:hidden')
  expect(wrapper.find('[data-test="recurring-sort"]').classes()).toContain('max-md:hidden')
  expect(wrapper.find('input[type="search"]').classes()).not.toContain('max-md:hidden')
  await toggle.trigger('click')
  expect(wrapper.find('[data-test="recurring-status-filter"]').classes()).not.toContain('max-md:hidden')
  expect(wrapper.find('[data-test="recurring-sort"]').classes()).not.toContain('max-md:hidden')
  wrapper.unmount()
})
