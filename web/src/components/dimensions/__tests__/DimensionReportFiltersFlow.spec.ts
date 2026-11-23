import { defineComponent, h, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import DimensionReportFilters from '../DimensionReportFilters.vue'

// Skutečný řádek filtru (DimensionReportFilter), jen výběr hodnoty je náhrada.
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true },
    types: { value: [{ id: 1, name: 'Projekt', is_active: true }, { id: 2, name: 'Středisko', is_active: true }] },
    valueById: { value: new Map([[5, { id: 5, type_id: 1 }], [8, { id: 8, type_id: 2 }]]) },
    load: () => Promise.resolve(),
  }),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('../DimensionPicker.vue', () => ({
  default: { name: 'DimensionPicker', props: ['typeId', 'modelValue'], emits: ['update:modelValue'], template: '<div data-test="picker" />' },
}))

const Page = defineComponent({
  setup() {
    const ids = ref<number[]>([])
    const descendants = ref(true)
    return () => h(DimensionReportFilters, {
      valueIds: ids.value, descendants: descendants.value,
      'onUpdate:valueIds': (v: number[]) => { ids.value = v },
      'onUpdate:descendants': (v: boolean) => { descendants.value = v },
    })
  },
})

describe('DimensionReportFilters se skutečným řádkem filtru', () => {
  it('po výběru typu a hodnoty nabídne další dimenzi a druhou hodnotu přidá k první', async () => {
    const w = mount(Page)
    await flushPromises()
    expect(w.find('[data-test="dimension-report-filters-add"]').exists()).toBe(true)

    await w.find('select').setValue('1')
    w.findComponent({ name: 'DimensionPicker' }).vm.$emit('update:modelValue', 5)
    await flushPromises()
    const add = w.find('[data-test="dimension-report-filters-add"]')
    expect(add.exists()).toBe(true)
    expect(add.attributes('disabled')).toBeUndefined()

    await add.trigger('click')
    expect(w.findAll('[data-test="dimension-report-filters-row"]')).toHaveLength(2)
    await w.findAll('select')[1]!.setValue('2')
    w.findAllComponents({ name: 'DimensionPicker' })[1]!.vm.$emit('update:modelValue', 8)
    await flushPromises()
    expect(w.findComponent(DimensionReportFilters).props('valueIds')).toEqual([5, 8])
  })
})
