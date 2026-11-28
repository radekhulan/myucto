import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({
  retry: vi.fn(async () => ({})),
  cancel: vi.fn(async () => ({})),
  route: { params: { id: '7' } as Record<string, string>, query: {} },
  canReadEshop: true,
  listCategories: vi.fn(async () => [
    { id: 4, name: 'Nářadí', depth: 0, archived: false },
    { id: 5, name: 'Ruční nářadí', depth: 1, archived: false },
    { id: 6, name: 'Archiv', depth: 0, archived: true },
  ]),
  createCycleCount: vi.fn(async (payload: Record<string, unknown>) => ({ id: 3, warehouse_id: 1, location_id: null, category_id: payload.category_id ?? null, category_name: 'Ruční nářadí', status: 'queued', take_date: '2026-09-01', note: null, preparation_job_id: 9, lines: [] })),
  createTake: vi.fn(async () => ({ id: 8 })),
  listTakes: vi.fn(async () => []),
}))
vi.mock('@/api/stock', () => ({ stockApi: {
  listWarehouses: vi.fn(async () => [{ id: 1, name: 'Hlavní', is_active: true }]),
  listLocations: vi.fn(async () => [
    { id: 11, warehouse_id: 1, code: 'A-1', name: 'Horní', is_active: true },
    { id: 12, warehouse_id: 1, code: 'A-2', name: 'Dolní', is_active: false },
    { id: 13, warehouse_id: 2, code: 'B-1', name: 'Vedlejší', is_active: true },
  ]),
  listTakes: mocks.listTakes,
  createTake: mocks.createTake,
  listCycleCounts: vi.fn(async () => [
    { id: 2, warehouse_id: 1, location_id: null, category_id: 5, category_name: 'Ruční nářadí', status: 'counting', take_date: '2026-09-01', note: null, preparation_job_id: null, lines: [] },
    { id: 1, warehouse_id: 1, location_id: null, category_id: null, category_name: null, status: 'closed', take_date: '2026-08-01', note: null, preparation_job_id: null, lines: [] },
  ]),
  createCycleCount: mocks.createCycleCount,
  getTake: vi.fn(async () => ({ id: 7, warehouse_id: 1, take_date: '2026-09-01', status: 'preparing', lines: [], preparation_job: { id: 9, status: 'failed', checkpoint: 0, total: 10 } })),
} }))
vi.mock('@/api/eshop', () => ({ eshopApi: { listCategories: mocks.listCategories } }))
vi.mock('@/api/catalogJobs', () => ({ catalogJobsApi: { retryTakePreparation: mocks.retry, cancelTakePreparation: mocks.cancel } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: (key: string) => key !== 'eshop' || mocks.canReadEshop }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value }))
vi.mock('vue-router', () => ({ useRoute: () => mocks.route, useRouter: () => ({ push: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key) }) }))

import TakeWizard from '../TakeWizard.vue'

const mountOptions = { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } }

describe('stock take preparation recovery', () => {
  it('shows retry while the take remains preparing after its job failed', async () => {
    mocks.route.params = { id: '7' }
    const wrapper = shallowMount(TakeWizard, mountOptions)
    try {
      await flushPromises()
      expect(wrapper.text()).toContain('stock.takes.preparation_failed')
      const retry = wrapper.findAll('button').find(button => button.text().includes('common.retry'))
      expect(retry).toBeDefined()
      await retry!.trigger('click')
      expect(mocks.retry).toHaveBeenCalledWith(7)
      const cancel = wrapper.findAll('button').find(button => button.text().includes('common.cancel'))
      expect(cancel).toBeDefined()
      await cancel!.trigger('click')
      expect(mocks.cancel).toHaveBeenCalledWith(7)
    } finally { wrapper.unmount() }
  })
})

it('filtruje inventury a zakládá inventuru podle zvolené lokace', async () => {
  mocks.route.params = {}
  mocks.listTakes.mockClear()
  mocks.createTake.mockClear()
  const wrapper = shallowMount(TakeWizard, mountOptions)
  try {
    await flushPromises()
    const locationSelect = (inputId: string) => wrapper.findAllComponents({ name: 'SearchableSelect' }).find(select => select.props('inputId') === inputId)!
    const filter = locationSelect('stock-take-location-filter')
    filter.vm.$emit('update:modelValue', 11)
    await flushPromises()
    expect(mocks.listTakes).toHaveBeenLastCalledWith({ location_id: 11 })
    filter.vm.$emit('update:modelValue', null)
    await flushPromises()
    expect(mocks.listTakes).toHaveBeenLastCalledWith({})

    await wrapper.findAll('button').find(button => button.text() === 'stock.takes.new')!.trigger('click')
    await wrapper.get('#stock-take-warehouse').setValue('1')
    const createLocation = locationSelect('stock-take-location')
    expect(createLocation.props('options')).toMatchObject([{ value: 11 }])
    createLocation.vm.$emit('update:modelValue', 11)
    const people = wrapper.findAll('input:not([type])')
    await people[0]!.setValue('Skladník')
    await people[1]!.setValue('Inventurník')
    await wrapper.findAll('button').find(button => button.text() === 'common.create')!.trigger('click')
    await flushPromises()
    expect(mocks.createTake).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({ warehouse_id: 1, location_id: 11 }))
  } finally { wrapper.unmount() }
})

describe('cycle count scoped to a category', () => {
  beforeEach(() => { mocks.canReadEshop = true; mocks.listCategories.mockClear() })

  it('creates the cycle count for the chosen category including subcategories', async () => {
    mocks.route.params = {}
    const wrapper = shallowMount(TakeWizard, mountOptions)
    try {
      await flushPromises()
      const category = wrapper.find('[data-testid="cycle-category"]')
      expect(category.exists()).toBe(true)
      const options = category.findAll('option').map(o => o.text())
      expect(options).toContain('Nářadí')
      expect(options.some(o => o.includes('Ruční nářadí'))).toBe(true)
      expect(options).not.toContain('Archiv')
      await wrapper.find('[data-testid="cycle-warehouse"]').setValue('1')
      await category.setValue('5')
      await wrapper.find('[data-testid="cycle-create"]').trigger('click')
      await flushPromises()
      expect(mocks.createCycleCount).toHaveBeenCalledWith(expect.objectContaining({ warehouse_id: 1, category_id: 5 }))
    } finally { wrapper.unmount() }
  })

  it('shows the category scope in the list of cycle counts', async () => {
    mocks.route.params = {}
    const wrapper = shallowMount(TakeWizard, mountOptions)
    try {
      await flushPromises()
      const rows = wrapper.findAll('[data-testid="cycle-row"]')
      expect(rows).toHaveLength(2)
      expect(rows[0].text()).toContain('stock.cycles.scope_category:{"name":"Ruční nářadí"}')
      expect(rows[1].text()).not.toContain('stock.cycles.scope_category')
    } finally { wrapper.unmount() }
  })

  it('does not request e-shop categories without the e-shop read permission', async () => {
    mocks.route.params = {}
    mocks.canReadEshop = false
    const wrapper = shallowMount(TakeWizard, mountOptions)
    try {
      await flushPromises()
      expect(mocks.listCategories).not.toHaveBeenCalled()
      expect(wrapper.find('[data-testid="cycle-category"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="cycle-create"]').exists()).toBe(true)
    } finally { wrapper.unmount() }
  })
})
