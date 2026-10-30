import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import DashboardBoard from '@/components/dashboard/board/DashboardBoard.vue'
import WidgetResizeHandle from '@/components/dashboard/board/WidgetResizeHandle.vue'
import type { WidgetDefinition, WidgetInstance } from '../types'
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user:{id:1}, permissions:{},license:{} }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId:1,hasSupplier:true,currentSupplier:{id:1} }) }))
vi.mock('@/composables/useWindowRefresh', () => ({ useWindowRefresh: () => ({}) }))
vi.mock('@/dashboard/api', () => {
  const instance = { id:'one',type:'business.monthly',span:6,height:'auto',options:{} }
  const layout = { schemaVersion:1,presetVersion:1,widgets:[instance] }
  return { dashboardBoardApi: {
    catalog: () => Promise.resolve({ board:'home',widgets:[{type:'business.monthly',titleKey:'monthly.title',descriptionKey:'monthly.description',spans:[6,9,12],defaultSpan:6,heights:['auto','compact','normal','tall'],defaultHeight:'auto',repeatable:false,options:{}}], defaultLayout:layout }),
    layout: () => Promise.resolve({ layout,revision:'r1' }), query: () => Promise.resolve({ widgets:{one:{status:'empty'}} }),
    save: vi.fn(), reset:vi.fn(),
  } }
})
const def: WidgetDefinition = { type:'business.monthly',titleKey:'monthly',descriptionKey:'monthly',spans:[6,9,12],defaultSpan:6,heights:['auto','compact','normal','tall'],options:{},repeatable:false }
const instance: WidgetInstance = { id:'test',type:'business.monthly',span:6,height:'normal',options:{} }
describe('dashboard editor controls', () => {
  it('places company heading and edit actions in one shared header row', async () => {
    const wrapper = mount(DashboardBoard,{props:{board:'home'},slots:{heading:'<h1>Synthetic company</h1>'}});await flushPromises()
    const header=wrapper.find('header');expect(header.find('h1').text()).toBe('Synthetic company');expect(header.findAll('button').some(b=>b.text().includes('dashboardBoard.edit'))).toBe(true);wrapper.unmount()
  })
  it('removing a singleton returns it to the catalog and allows reinsertion', async () => {
    const wrapper = mount(DashboardBoard, { props:{board:'home'},global:{stubs:{WidgetContent:true}} }); await flushPromises()
    await wrapper.findAll('button').find(b => b.text().includes('dashboardBoard.edit'))!.trigger('click')
    expect(wrapper.findAll('h3')).toHaveLength(0)
    await wrapper.findAll('button').find(b => b.attributes('aria-label') === 'dashboardBoard.remove')!.trigger('click')
    expect(wrapper.find('h3').text()).toBe('monthly.title'); expect(wrapper.text()).toContain('monthly.description')
    await wrapper.findAll('button').find(b => b.text().includes('dashboardBoard.add') && !b.text().includes('addDivider'))!.trigger('click')
    expect(wrapper.findAll('article')).toHaveLength(1); expect(wrapper.findAll('h3')).toHaveLength(0); wrapper.unmount()
  })
  it('keeps measured content and auto row span identical while editing and opening settings', async () => {
    const wrapper = mount(DashboardBoard, { props:{board:'home'},global:{stubs:{WidgetContent:true}} }); await flushPromises()
    const before = wrapper.find('.widget-inner').html()
    const rows = wrapper.find('article').attributes('style')
    await wrapper.findAll('button').find(b => b.text().includes('dashboardBoard.edit'))!.trigger('click')
    expect(wrapper.find('.widget-inner').html()).toBe(before)
    expect(wrapper.find('article').attributes('style')).toBe(rows)
    expect(wrapper.findAll('[data-resize-axis]').map(handle => handle.attributes('data-resize-axis'))).toEqual(['width','height','both'])
    for (const handle of wrapper.findAll('[data-resize-axis]')) expect(wrapper.find('.widget-inner').element.contains(handle.element)).toBe(false)
    await wrapper.find('button[aria-label="dashboardBoard.options"]').trigger('click')
    expect(wrapper.find('[data-widget-settings]').exists()).toBe(true)
    expect(wrapper.find('.widget-inner').element.contains(wrapper.find('[data-widget-settings]').element)).toBe(false)
    expect(wrapper.find('.widget-inner').html()).toBe(before)
    expect(wrapper.find('article').attributes('style')).toBe(rows)
    expect(wrapper.find('article').attributes('draggable')).toBeUndefined()
    wrapper.unmount()
  })
  it('snaps pointer resizing to supported width and height bounds', async () => {
    const wrapper = mount(WidgetResizeHandle,{props:{instance,definition:def,cellWidth:80,cellHeight:45}})
    await wrapper.trigger('pointerdown',{clientX:0,clientY:0,pointerId:1})
    await wrapper.trigger('pointermove',{clientX:10000,clientY:10000,pointerId:1})
    expect(wrapper.emitted('resize')?.at(-1)).toEqual([12,'tall'])
    await wrapper.trigger('pointermove',{clientX:-10000,clientY:-10000,pointerId:1})
    expect(wrapper.emitted('resize')?.at(-1)).toEqual([6,'compact']); await wrapper.trigger('pointerup',{pointerId:1}); wrapper.unmount()
  })
  it('offers keyboard resizing with the same registry constraints', async () => {
    const wrapper = mount(WidgetResizeHandle,{props:{instance,definition:def,cellWidth:80,cellHeight:45}})
    await wrapper.trigger('keydown',{key:'ArrowRight'}); expect(wrapper.emitted('resize')?.at(-1)).toEqual([9,'normal'])
    await wrapper.trigger('keydown',{key:'ArrowDown'}); expect(wrapper.emitted('resize')?.at(-1)).toEqual([6,'tall']); wrapper.unmount()
  })
  it('keeps automatic-only heights when dragging and ignores disabled controls', async () => {
    const wrapper = mount(WidgetResizeHandle,{props:{instance:{...instance,height:'auto'},definition:{...def,heights:['auto']},cellWidth:80,cellHeight:45}})
    await wrapper.trigger('pointerdown',{clientX:0,clientY:0,pointerId:1}); await wrapper.trigger('pointermove',{clientX:300,clientY:1000,pointerId:1})
    expect(wrapper.emitted('resize')?.at(-1)).toEqual([9,'auto'])
    await wrapper.setProps({disabled:true}); const before=wrapper.emitted('resize')?.length; await wrapper.trigger('keydown',{key:'ArrowRight'}); expect(wrapper.emitted('resize')?.length).toBe(before); wrapper.unmount()
  })
})
