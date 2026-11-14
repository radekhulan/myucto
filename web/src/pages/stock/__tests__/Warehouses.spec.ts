import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Warehouses from '../Warehouses.vue'

const mocks = vi.hoisted(() => ({ listWarehouses: vi.fn(), listLocations: vi.fn(), canWrite: vi.fn() }))
vi.mock('@/api/stock', () => ({ stockApi: mocks }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: mocks.canWrite }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => value.toFixed(2) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

beforeEach(() => {
  vi.resetAllMocks()
  mocks.canWrite.mockReturnValue(false)
  mocks.listWarehouses.mockResolvedValue([
    { id: 1, code: 'MAIN', name: 'Main', is_default: true, is_active: true, value: '0' },
    { id: 2, code: 'SECOND', name: 'Secondary', is_default: false, is_active: true, value: '0' },
  ])
})

describe('Warehouses', () => {
  it('načte pozice až po otevření skladu a stránkuje a filtruje jeho seznam', async () => {
    mocks.listLocations.mockResolvedValue(Array.from({ length: 23 }, (_, index) => ({
      id: index + 1, warehouse_id: 1, name: 'Regál', code: `BIN-${100 + index}`, is_active: index !== 2,
    })))
    const wrapper = mount(Warehouses, { global: { stubs: { EmptyState: true, Modal: true, CodeNameFields: true } } })
    try {
      await flushPromises()
      expect(mocks.listLocations).not.toHaveBeenCalled()
      await wrapper.findAll('button[aria-expanded]')[0]!.trigger('click')
      await flushPromises()
      expect(mocks.listLocations).toHaveBeenCalledExactlyOnceWith(1)
      const positions = wrapper.get('section')
      expect(positions.findAll('li')).toHaveLength(20)
      expect(positions.text()).not.toContain('stock.locations.new')
      await positions.findAll('button').find(button => button.text() === 'common.next')!.trigger('click')
      expect(positions.findAll('li')).toHaveLength(3)
      await positions.get('input').setValue('regal BIN-102')
      expect(positions.findAll('li')).toHaveLength(1)
      expect(positions.text()).toContain('BIN-102')
      await positions.get('select').setValue('active')
      expect(positions.text()).toContain('stock.locations.no_matches')
    } finally {
      wrapper.unmount()
    }
  })

  it('po přepnutí skladu ignoruje pozdější výsledek předchozího skladu', async () => {
    let finishFirst!: (rows: unknown[]) => void
    mocks.listLocations
      .mockImplementationOnce(() => new Promise<unknown[]>(resolve => { finishFirst = resolve }))
      .mockResolvedValueOnce([{ id: 2, warehouse_id: 2, code: 'SECOND-BIN', name: 'Rack', is_active: true }])
    const wrapper = mount(Warehouses, { global: { stubs: { EmptyState: true, Modal: true, CodeNameFields: true } } })
    try {
      await flushPromises()
      const buttons = wrapper.findAll('button[aria-expanded]')
      await buttons[0]!.trigger('click')
      await buttons[1]!.trigger('click')
      await flushPromises()
      finishFirst([{ id: 1, warehouse_id: 1, code: 'FIRST-BIN', name: 'Shelf', is_active: true }])
      await flushPromises()
      expect(mocks.listLocations.mock.calls).toEqual([[1], [2]])
      expect(wrapper.get('section').text()).toContain('SECOND-BIN')
      expect(wrapper.text()).not.toContain('FIRST-BIN')
    } finally {
      wrapper.unmount()
    }
  })
})
