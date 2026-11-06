import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DimensionReportFilters from '../DimensionReportFilters.vue'
import { parseDimensionValueIds } from '@/utils/dimensionFilter'

vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({ enabled: { value: true }, load: () => Promise.resolve() }),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const stubs = {
  DimensionReportFilter: { name: 'DimensionReportFilter', props: { valueId: Number, descendants: Boolean, hideDescendants: Boolean }, template: '<div data-test="row-filter" />' },
}

function rowFilters(w: ReturnType<typeof mount>) {
  return w.findAllComponents({ name: 'DimensionReportFilter' })
}

describe('DimensionReportFilters', () => {
  it('bez filtru ukáže jeden prázdný řádek a neaktivní přidání, bez podřízených', () => {
    const w = mount(DimensionReportFilters, { props: { valueIds: [], descendants: true }, global: { stubs } })
    expect(rowFilters(w)).toHaveLength(1)
    expect(rowFilters(w)[0]!.props()).toMatchObject({ valueId: null, hideDescendants: true })
    expect(w.get('[data-test="dimension-report-filters-add"]').attributes('disabled')).toBeDefined()
    expect(w.find('input[type="checkbox"]').exists()).toBe(false)
  })

  it('přidá řádek pro další dimenzi a do modelu pošle jen vybrané hodnoty', async () => {
    const w = mount(DimensionReportFilters, { props: { valueIds: [5], descendants: true }, global: { stubs } })
    await w.get('[data-test="dimension-report-filters-add"]').trigger('click')
    expect(rowFilters(w)).toHaveLength(2)
    expect(w.emitted('update:valueIds')).toBeUndefined()
    expect(w.get('[data-test="dimension-report-filters-add"]').attributes('disabled')).toBeDefined()

    rowFilters(w)[1]!.vm.$emit('update:valueId', 8)
    expect(w.emitted('update:valueIds')!.at(-1)).toEqual([[5, 8]])
  })

  it('odebrání řádku hodnotu z filtru vyřadí', async () => {
    const w = mount(DimensionReportFilters, { props: { valueIds: [5, 8], descendants: false }, global: { stubs } })
    expect(rowFilters(w)).toHaveLength(2)
    const boxes = w.findAll('input[type="checkbox"]')
    expect(boxes).toHaveLength(1)
    await w.findAll('[data-test="dimension-report-filters-remove"]')[0]!.trigger('click')
    expect(w.emitted('update:valueIds')!.at(-1)).toEqual([[8]])
    expect(rowFilters(w)).toHaveLength(1)
  })

  it('po odebrání prvního řádku zůstane rozpracovaný výběr dalšího řádku na svém místě', async () => {
    const w = mount(DimensionReportFilters, { props: { valueIds: [5], descendants: true }, global: { stubs } })
    await w.get('[data-test="dimension-report-filters-add"]').trigger('click')
    const pending = rowFilters(w)[1]!.vm
    await w.findAll('[data-test="dimension-report-filters-remove"]')[0]!.trigger('click')
    expect(w.emitted('update:valueIds')!.at(-1)).toEqual([[]])
    expect(rowFilters(w)).toHaveLength(1)
    expect(rowFilters(w)[0]!.vm).toBe(pending)
  })

  it('URL parametr čte čárkami oddělené kladné id bez duplicit', () => {
    expect(parseDimensionValueIds('5')).toEqual([5])
    expect(parseDimensionValueIds('5, 8,5,x,-1,0,')).toEqual([5, 8])
    expect(parseDimensionValueIds(undefined)).toEqual([])
    expect(parseDimensionValueIds(['3', '4'])).toEqual([3, 4])
  })
})
