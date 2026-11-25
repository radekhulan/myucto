import { expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

// Telefon: stav a řazení opakovaných faktur jsou za tlačítkem „Filtry"; hledání
// zůstává vidět. Jen `max-md:` třídy, desktop beze změny.

const m = vi.hoisted(() => ({ tagsEnabled: false }))
const tpl = { id: 1, name: 'Hosting', status: 'active', client_name: 'Syntetický klient', items: [], tags: [{ id: 3, name: 'Servis' }] }
vi.mock('@/api/recurring', () => ({ recurringApi: { list: async () => ({ data: [tpl], meta: { total: 1, page: 1, pages: 1 } }) } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { tags_enabled: m.tagsEnabled } }) }))
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
import EntityTagFilter from '@/components/tags/EntityTagFilter.vue'

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

it('bez zapnutých štítků nemá seznam filtr ani štítky u šablon', async () => {
  m.tagsEnabled = false
  const wrapper = shallowMount(RecurringList)
  await flushPromises()
  expect(wrapper.findComponent(EntityTagFilter).exists()).toBe(false)
  expect(wrapper.text()).not.toContain('Servis')
  wrapper.unmount()
})

it('se zapnutými štítky má seznam filtr i štítky u šablon', async () => {
  m.tagsEnabled = true
  const wrapper = shallowMount(RecurringList)
  await flushPromises()
  expect(wrapper.findComponent(EntityTagFilter).exists()).toBe(true)
  expect(wrapper.text()).toContain('Servis')
  wrapper.unmount()
})
