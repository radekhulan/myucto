import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DimensionReportFilters from '../DimensionReportFilters.vue'

vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true },
    load: () => Promise.resolve(),
    valueById: { value: new Map([[5, { id: 5, type_id: 11 }], [8, { id: 8, type_id: 12 }]]) },
  }),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const stubs = {
  DimensionReportFilter: { name: 'DimensionReportFilter', props: { valueId: Number, descendants: Boolean, hideDescendants: Boolean, lockTypeId: Number }, template: '<div />' },
}

describe('DimensionReportFilters – jeden typ (evidence DPH)', () => {
  it('další řádky nabízí jen typ první vybrané hodnoty', async () => {
    const w = mount(DimensionReportFilters, { props: { valueIds: [5], descendants: true, singleType: true }, global: { stubs } })
    await w.get('[data-test="dimension-report-filters-add"]').trigger('click')
    const rows = w.findAllComponents({ name: 'DimensionReportFilter' })
    expect(rows[0]!.props('lockTypeId')).toBeNull()
    expect(rows[1]!.props('lockTypeId')).toBe(11)
  })

  it('bez příznaku zůstává výběr typů volný', async () => {
    const w = mount(DimensionReportFilters, { props: { valueIds: [5], descendants: true }, global: { stubs } })
    await w.get('[data-test="dimension-report-filters-add"]').trigger('click')
    expect(w.findAllComponents({ name: 'DimensionReportFilter' })[1]!.props('lockTypeId')).toBeNull()
  })
})
