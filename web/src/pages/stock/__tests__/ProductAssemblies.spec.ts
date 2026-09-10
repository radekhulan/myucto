import { defineComponent, nextTick } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({
  createAssembly: vi.fn(async (payload: Record<string, unknown>) => ({
    id: 1,
    supplier_id: 1,
    stock_item_id: payload.stock_item_id,
    warehouse_id: payload.warehouse_id,
    quantity: payload.quantity,
    status: 'posted',
    value_total: '20.00',
    issue_document_id: 11,
    receipt_document_id: 12,
    definition: payload.definition,
    components: [],
    created_at: '2099-01-01',
  })),
  targetMode: 'serial' as 'none' | 'serial',
  componentMode: 'serial' as 'none' | 'serial',
}))

vi.mock('@/api/stock', () => ({ stockApi: {
  listAssemblies: vi.fn(async () => ({ items: [], pagination: { page: 1, limit: 25, total: 0, pages: 1 } })),
  listWarehouses: vi.fn(async () => [{ id: 5, code: 'MAIN', name: 'Main', is_default: true, is_active: true }]),
  listLocations: vi.fn(async () => [{ id: 9, supplier_id: 1, warehouse_id: 5, code: 'A-01', name: 'Shelf', is_active: true }]),
  searchItems: vi.fn(async () => []),
  getItem: vi.fn(async (id: number) => id === 10
    ? { id, sku: 'PRODUCT', name: 'Product', unit: 'ks', item_type: 'product', is_active: true, is_stocked: true, tracking_mode: mocks.targetMode }
    : { id, sku: 'RECIPE', name: 'Recipe', unit: 'ks', item_type: 'product', is_active: true, is_stocked: false, tracking_mode: 'none' }),
  availability: vi.fn(async () => ({ 30: '2.000' })),
  itemTracking: vi.fn(async (id: number) => id === 10
    ? { tracking_mode: mocks.targetMode, base_unit: 'ks', units: [], inventory: [], history: [] }
    : { tracking_mode: mocks.componentMode, base_unit: 'ks', units: [], history: [], inventory: mocks.componentMode === 'none' ? [] : [
        { stock_tracking_unit_id: 101, tracking_type: 'serial', lot_code: null, serial_number: 'COMP-1', expires_on: null, warehouse_id: 5, location_id: 9, warehouse_code: 'MAIN', location_code: 'A-01', quantity: '1.000' },
        { stock_tracking_unit_id: 102, tracking_type: 'serial', lot_code: null, serial_number: 'COMP-2', expires_on: null, warehouse_id: 5, location_id: 9, warehouse_code: 'MAIN', location_code: 'A-01', quantity: '1.000' },
      ] }),
  createAssembly: mocks.createAssembly,
  reverseAssembly: vi.fn(),
} }))
vi.mock('@/api/eshop', () => ({ eshopApi: {
  getProductSet: vi.fn(async () => ({
    set: { definition: { components: [{ item_id: 30, quantity: '1.000' }], groups: [], prices: {} } },
    definitions: {},
    cards: [{ id: 30, sku: 'COMP', name: 'Component', unit: 'ks' }],
  })),
} }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import ProductAssemblies from '../ProductAssemblies.vue'

const SearchableSelectStub = defineComponent({
  name: 'SearchableSelect',
  props: ['options'],
  emits: ['update:modelValue', 'search'],
  template: '<button class="search-select" type="button"><slot /></button>',
})

async function chooseTargetAndRecipe(wrapper: ReturnType<typeof mount>) {
  const selects = wrapper.findAllComponents(SearchableSelectStub)
  selects[0].vm.$emit('update:modelValue', 10)
  await flushPromises()
  selects[1].vm.$emit('update:modelValue', 20)
  await flushPromises()
}

describe('product assembly tracking', () => {
  it('keeps selected labels when another search replaces the results', async () => {
    const wrapper = mount(ProductAssemblies, {
      global: { stubs: { SearchableSelect: SearchableSelectStub, RouterLink: { template: '<a><slot /></a>' }, EmptyState: true } },
    })
    try {
      await flushPromises()
      await chooseTargetAndRecipe(wrapper)
      const selects = wrapper.findAllComponents(SearchableSelectStub)
      selects[1].vm.$emit('search', 'different search')
      await flushPromises()
      expect(selects[0].props('options')).toContainEqual(expect.objectContaining({ value: 10, label: 'Product' }))
      expect(selects[1].props('options')).toContainEqual(expect.objectContaining({ value: 20, label: 'Recipe' }))
    } finally {
      wrapper.unmount()
    }
  })

  it('sends exact component identities and entered product serials', async () => {
    mocks.targetMode = 'serial'
    mocks.componentMode = 'serial'
    const wrapper = mount(ProductAssemblies, {
      global: { stubs: { SearchableSelect: SearchableSelectStub, RouterLink: { template: '<a><slot /></a>' }, EmptyState: true } },
    })
    try {
      await flushPromises()
      await chooseTargetAndRecipe(wrapper)
      await wrapper.find('input[inputmode="decimal"]').setValue('2')
      await nextTick()
      for (const checkbox of wrapper.findAll('input[type="checkbox"]')) await checkbox.setValue(true)
      const serials = wrapper.findAll('input[autocomplete="off"]')
      expect(serials).toHaveLength(2)
      await serials[0].setValue('PRODUCT-1')
      await serials[1].setValue('PRODUCT-2')
      const create = wrapper.findAll('button').find(button => button.text().includes('stock.assemblies.create'))
      expect(create?.attributes('disabled')).toBeUndefined()
      await create!.trigger('click')
      await flushPromises()

      expect(mocks.createAssembly).toHaveBeenCalledWith(expect.objectContaining({
        component_tracking_allocations: {
          30: [
            expect.objectContaining({ stock_tracking_unit_id: 101, serial_number: 'COMP-1', quantity: '1', location_id: 9 }),
            expect.objectContaining({ stock_tracking_unit_id: 102, serial_number: 'COMP-2', quantity: '1', location_id: 9 }),
          ],
        },
        product_tracking_allocations: [
          expect.objectContaining({ serial_number: 'PRODUCT-1', quantity: '1' }),
          expect.objectContaining({ serial_number: 'PRODUCT-2', quantity: '1' }),
        ],
      }))
    } finally {
      wrapper.unmount()
    }
  })

  it('keeps the classic untracked payload free of allocation fields', async () => {
    mocks.targetMode = 'none'
    mocks.componentMode = 'none'
    mocks.createAssembly.mockClear()
    const wrapper = mount(ProductAssemblies, {
      global: { stubs: { SearchableSelect: SearchableSelectStub, RouterLink: { template: '<a><slot /></a>' }, EmptyState: true } },
    })
    try {
      await flushPromises()
      await chooseTargetAndRecipe(wrapper)
      const create = wrapper.findAll('button').find(button => button.text().includes('stock.assemblies.create'))
      await create!.trigger('click')
      await flushPromises()
      const payload = mocks.createAssembly.mock.calls[0][0]
      expect(payload).not.toHaveProperty('component_tracking_allocations')
      expect(payload).not.toHaveProperty('product_tracking_allocations')
    } finally {
      wrapper.unmount()
    }
  })
})
