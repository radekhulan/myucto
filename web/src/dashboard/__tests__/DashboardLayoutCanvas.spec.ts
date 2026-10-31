import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import DashboardLayoutCanvas from '@/components/dashboard/layout/DashboardLayoutCanvas.vue'
import type { DashboardLayoutState } from '../useDashboardLayout'
import type { LayoutItem } from '../types'
vi.mock('@/components/dashboard/layout/DashboardWidgetCard.vue', () => ({ default: { props: ['item'], template: '<article class="synthetic-widget" />' } }))
const item = (id: string, kind: LayoutItem['kind'] = 'section'): LayoutItem => ({ id, type: id, kind, span: 12, height: 'auto', options: {} })
function state(items: LayoutItem[]) { return { board: 'home', visible: ref(items) } as unknown as DashboardLayoutState }
describe('layout canvas keeps original content', () => {
  it('renders existing slots directly in their original order, without card wrappers or a new grid', () => {
    const wrapper = mount(DashboardLayoutCanvas, { props: { state: state([item('sales'), item('purchases')]) },
      slots: { sales: '<section class="original-sales">Revenue and year comparison</section>', purchases: '<section class="original-costs">Costs YTD</section>' } })
    expect(wrapper.classes()).toEqual(['space-y-6'])
    expect([...wrapper.element.children].map(child => child.className)).toEqual(['original-sales', 'original-costs'])
    expect(wrapper.find('article').exists()).toBe(false)
    wrapper.unmount()
  })
  it('preserves component instances while reordering and omits self-hidden sections without empty boxes', async () => {
    const mounted = vi.fn()
    const component = defineComponent({ setup() { mounted(); return () => h('section', { class: 'original' }, 'Current content') } })
    const model = state([item('sales'), item('hidden'), item('purchases')])
    const wrapper = mount(DashboardLayoutCanvas, { props: { state: model }, slots: { sales: () => h(component), hidden: () => [], purchases: '<section class="costs" />' } })
    expect(wrapper.element.children).toHaveLength(2)
    model.visible.value = [item('purchases'), item('hidden'), item('sales')]
    await wrapper.vm.$nextTick()
    expect([...wrapper.element.children].map(child => child.className)).toEqual(['costs', 'original'])
    expect(mounted).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
  it('adds a grid only for explicitly added widgets and retains the original section intact', () => {
    const wrapper = mount(DashboardLayoutCanvas, { props: { state: state([item('sales'), item('synthetic', 'widget')]) }, slots: { sales: '<section class="original-sales"><h2>Original</h2></section>' } })
    expect(wrapper.classes()).toContain('dashboard-layout-grid')
    expect(wrapper.find('.original-sales').classes()).toContain('dashboard-original-section')
    expect(wrapper.find('.original-sales h2').text()).toBe('Original')
    expect(wrapper.find('.synthetic-widget').exists()).toBe(true)
    wrapper.unmount()
  })
})
