import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { KeepAlive, defineComponent, h } from 'vue'
import type { Car, OdometerGap, TripCategory, TripTemplate } from '@/api/logbook'

const m = vi.hoisted(() => ({
  listGaps: vi.fn(),
  listTemplates: vi.fn(),
  fillGap: vi.fn(),
  listReadings: vi.fn(),
  createReading: vi.fn(),
  deleteReading: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  denied: [] as string[],
}))

vi.mock('@/api/logbook', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/logbook')>()),
  logbookApi: { ...m },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: (p: string) => !m.denied.includes(p) }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.toastSuccess, error: m.toastError }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (d: string) => d }))

import OdometerGapsPanel from '@/pages/logbook/OdometerGapsPanel.vue'

const gap: OdometerGap = {
  car_id: 3, car_registration: '1AB2345', car_name: 'iX2', reading_id: 21,
  date_from: '2099-03-01', date_to: '2099-03-02', odometer_from: 1000, odometer_to: 1087, km: 87,
}
const template: TripTemplate = {
  id: 7, car_id: null, car_registration: null, car_name: null, label: 'Domov → kancelář', origin: 'Domov',
  destination: 'Kancelář', purpose: 'Jednání', distance_km: 38.4, category_id: 1, category_label: 'Služební',
  category_is_private: false, time_start: '07:30', time_end: '08:10', display_order: 10, is_archived: false,
}
const otherCarTemplate: TripTemplate = { ...template, id: 8, car_id: 99, label: 'Jiné auto' }
const categories: TripCategory[] = [
  { id: 1, code: 'business', label: 'Služební', is_private: false, is_default: true, display_order: 10, is_archived: false },
  { id: 2, code: 'private', label: 'Soukromá', is_private: true, is_default: false, display_order: 20, is_archived: false },
]
const cars = [{ id: 3, registration: '1AB2345', name: 'iX2', is_default: true }] as Car[]

function mountPanel(cats: TripCategory[] = categories) {
  return mount(OdometerGapsPanel, {
    props: { cars, categories: cats },
    global: { stubs: { DateInput: { props: ['modelValue'], template: '<input :value="modelValue" />' } } },
  })
}

describe('OdometerGapsPanel.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.denied = []
    m.listGaps.mockResolvedValue([gap])
    m.listTemplates.mockResolvedValue([template, otherCarTemplate])
    m.fillGap.mockResolvedValue({ trip_ids: [1, 2, 3], gap })
  })

  it('lists kilometres to fill in', async () => {
    const w = mountPanel()
    await flushPromises()

    expect(w.text()).toContain('logbook.gaps_title')
    expect(w.text()).toContain('87 km')
  })

  it('fills the gap from regular trips with the rest as a private trip', async () => {
    const w = mountPanel()
    await flushPromises()
    await w.find('li button').trigger('click')

    const templateButtons = w.findAll('form button[type="button"]').filter(b => b.text().includes('Domov → kancelář'))
    expect(w.text()).not.toContain('Jiné auto')
    await templateButtons[0].trigger('click')
    await templateButtons[0].trigger('click')
    expect(w.text()).toContain('logbook.gap_remainder')

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(m.fillGap).toHaveBeenCalledWith({
      reading_id: 21,
      trips: [
        expect.objectContaining({ template_id: 7, trip_date: '2099-03-02', distance_km: 38.4, origin: 'Domov' }),
        expect.objectContaining({ template_id: 7, trip_date: '2099-03-02', distance_km: 38.4 }),
      ],
      remainder_private: true,
      remainder_purpose: 'logbook.gap_private_purpose',
    })
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('does not submit trips longer than the gap', async () => {
    m.listTemplates.mockResolvedValue([{ ...template, distance_km: 60 }])
    const w = mountPanel()
    await flushPromises()
    await w.find('li button').trigger('click')
    const add = w.findAll('form button[type="button"]').find(b => b.text().includes('Domov → kancelář'))!
    await add.trigger('click')
    await add.trigger('click')

    expect(w.find('form button[type="submit"]').attributes('disabled')).toBeDefined()
    await w.find('form').trigger('submit')
    expect(m.fillGap).not.toHaveBeenCalled()
  })

  it('cannot offer a private remainder without a private category', async () => {
    const w = mountPanel([categories[0]])
    await flushPromises()
    await w.find('li button').trigger('click')

    const checkbox = w.find('form input[type="checkbox"]')
    expect((checkbox.element as HTMLInputElement).checked).toBe(false)
    expect(checkbox.attributes('disabled')).toBeDefined()
    expect(w.text()).toContain('logbook.gap_no_private_category')
  })

  it('records an odometer reading for the chosen car', async () => {
    m.listReadings.mockResolvedValue([])
    m.createReading.mockResolvedValue({})
    const w = mountPanel()
    await flushPromises()

    await (w.vm as unknown as { openReadings: (id?: number) => Promise<void> }).openReadings(3)
    await flushPromises()
    await w.find('input[type="number"]').setValue(1200)
    await w.findAll('form').at(-1)!.trigger('submit')
    await flushPromises()

    expect(m.createReading).toHaveBeenCalledWith(expect.objectContaining({ car_id: 3, odometer: 1200 }))
    expect(m.listGaps).toHaveBeenCalledTimes(2)
  })

  it('offers deleting a reading only with the delete permission', async () => {
    m.listReadings.mockResolvedValue([{ id: 5, car_id: 3, reading_date: '2099-03-02', odometer: 1087 }])
    const open = async () => {
      const w = mountPanel()
      await flushPromises()
      await (w.vm as unknown as { openReadings: (id?: number) => Promise<void> }).openReadings(3)
      await flushPromises()
      return w
    }

    expect((await open()).findAll('button[title="common.delete"]')).toHaveLength(1)
    m.denied = ['logbook.delete']
    const readOnly = await open()
    expect(readOnly.text()).toContain('2099-03-02')
    expect(readOnly.findAll('button[title="common.delete"]')).toHaveLength(0)
  })

  it('reloads regular trips and gaps when the tab is shown again', async () => {
    const Host = defineComponent({
      props: { show: Boolean },
      setup: (p) => () => h(KeepAlive, null, [p.show ? h(OdometerGapsPanel, { cars, categories }) : h('div')]),
    })
    const w = mount(Host, {
      props: { show: true },
      global: { stubs: { DateInput: { props: ['modelValue'], template: '<input :value="modelValue" />' } } },
    })
    await flushPromises()
    expect(m.listTemplates).toHaveBeenCalledTimes(1)

    m.listTemplates.mockResolvedValue([template, { ...template, id: 9, label: 'Nová pravidelná cesta' }])
    await w.setProps({ show: false })
    await w.setProps({ show: true })
    await flushPromises()

    expect(m.listTemplates).toHaveBeenCalledTimes(2)
    expect(m.listGaps).toHaveBeenCalledTimes(2)
    await w.find('li button').trigger('click')
    expect(w.text()).toContain('Nová pravidelná cesta')
  })
})
