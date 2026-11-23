import { describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { ref } from 'vue'

const phoneRef = ref(false)
const car = { id: 3, registration: '1AB 2345', name: null, is_default: true, last_odometer: 1000 }

vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMonth: (v: string) => v }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, isDemo: false }) }))
vi.mock('@/composables/useTripTemplates', async () => {
  const { ref: vueRef } = await import('vue')
  return { useTripTemplates: () => ({ templates: vueRef([]), loadTemplates: vi.fn() }) }
})
vi.mock('@/api/logbook', () => ({
  logbookApi: {
    listTrips: vi.fn().mockResolvedValue({ data: [], meta: { total: 0, pages: 1 }, years: [] }),
    listCars: vi.fn(async () => [car]),
    listCategories: vi.fn().mockResolvedValue([]),
    tripPurposes: vi.fn().mockResolvedValue([]),
    tripPlaces: vi.fn().mockResolvedValue([]),
  },
}))

import TripsTab from '../TripsTab.vue'

async function openNewTrip() {
  const wrapper = shallowMount(TripsTab, { props: { openNewToken: 0 } })
  await flushPromises()
  await wrapper.setProps({ openNewToken: 1 })
  await flushPromises()
  return wrapper
}

describe('Formulář jízdy na telefonu', () => {
  it('mimo telefon ukáže všechna pole a čas nepředvyplní', async () => {
    phoneRef.value = false
    const wrapper = await openNewTrip()
    const modal = wrapper.get('[data-test="trip-modal"]')
    expect(modal.find('[data-test="trip-more-toggle"]').exists()).toBe(false)
    expect(modal.findAll('input[type="time"]')).toHaveLength(2)
    expect(modal.findAll('input[type="time"]').every(i => (i.element as HTMLInputElement).value === '')).toBe(true)
    expect(modal.get('[data-test="trip-odometer-end"]').attributes('inputmode')).toBeUndefined()
  })

  it('na telefonu nechá nahoře hlavní pole, ostatní pod Další a předvyplní konec jízdy', async () => {
    phoneRef.value = true
    const wrapper = await openNewTrip()
    const modal = wrapper.get('[data-test="trip-modal"]')
    expect(modal.get('[data-test="trip-odometer-end"]').attributes('inputmode')).toBe('numeric')
    expect(modal.findAll('input[type="time"]')).toHaveLength(0)
    await modal.get('[data-test="trip-more-toggle"]').trigger('click')
    const times = modal.findAll('input[type="time"]')
    expect(times).toHaveLength(2)
    expect((times[0]!.element as HTMLInputElement).value).toBe('')
    expect((times[1]!.element as HTMLInputElement).value).toMatch(/^\d{2}:\d{2}$/)
    expect(modal.get('[data-test="trip-modal-footer"]').classes()).toContain('max-md:sticky')
  })
})
