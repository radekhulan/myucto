import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import WidgetResizeHandle from '@/components/dashboard/board/WidgetResizeHandle.vue'
import { supportedSpans, type WidgetDefinition, type WidgetInstance } from '../types'
import manifest from '../../../shared/dashboard-widgets.json'
import DashboardBoard from '@/components/dashboard/board/DashboardBoard.vue'
const longLabel = 'SyntheticActionLabelWithoutSpaces'.repeat(8)
vi.mock('vue-i18n',async original=>({...await original<typeof import('vue-i18n')>(),useI18n:()=>({t:(key:string)=>key==='portal.quick_invoice'?'SyntheticActionLabelWithoutSpaces'.repeat(8):key,te:()=>false,locale:{value:'cs'}})}))
vi.mock('@/stores/auth',()=>({useAuthStore:()=>({user:{id:1},permissions:{},license:{}})}))
vi.mock('@/stores/supplier',()=>({useSupplierStore:()=>({currentSupplierId:1,hasSupplier:true,currentSupplier:{id:1}})}))
vi.mock('@/composables/useWindowRefresh',()=>({useWindowRefresh:()=>({})}))
vi.mock('@/dashboard/api',async()=>{
 const manifest=(await import('../../../shared/dashboard-widgets.json')).default
 const { supportedSpans } = await import('../types')
 const definitions=manifest.widgets.filter(w=>w.type==='actions.quick'||w.type==='actions.todo').map(w=>({...w,spans:supportedSpans(w)}))
 const layout={schemaVersion:1,presetVersion:1,widgets:definitions.map(def=>({id:def.type,type:def.type,span:def.defaultSpan,height:'auto',options:Object.fromEntries(Object.entries(def.options).map(([key,option])=>[key,option.default]))}))}
 return { dashboardBoardApi: {
  catalog: () => Promise.resolve({ board:'home',widgets:definitions,defaultLayout:layout }),
  layout: () => Promise.resolve({ layout,revision:'r1' }),
  query: () => Promise.resolve({ widgets: {
   'actions.quick': { status:'ok',data:{items:[{id:'invoices',link:'/invoices/new'}]} },
   'actions.todo': { status:'ok',data:{items:[{type:'synthetic',title:'SyntheticActionLabelWithoutSpaces'.repeat(8),link:'/bank'}]} },
  } }),
  save: vi.fn(),reset:vi.fn(),
 } }
})
describe('action widget widths from the shared manifest',()=>{
 it.each(['actions.quick','actions.todo'])('supports selector3/12 and pointerresize4/12 for %s without forcing long labels wide',async type=>{
  const wrapper=mount(DashboardBoard,{props:{board:'home'},global:{stubs:{RouterLink:{props:['to'],template:'<a :href="to"><slot /></a>'}}}});await flushPromises()
  await wrapper.findAll('button').find(b=>b.text().includes('dashboardBoard.edit'))!.trigger('click')
  const card=wrapper.find(`[data-drop-id="${type}"]`)
  expect(card.findAll('[data-resize-axis="width"]')).toHaveLength(1)
  expect(card.findAll('[data-resize-axis="height"]')).toHaveLength(type === 'actions.quick' ? 0 : 1)
  expect(card.findAll('[data-resize-axis="both"]')).toHaveLength(type === 'actions.quick' ? 0 : 1)
  await card.find('button[aria-label="dashboardBoard.options"]').trigger('click')
  const width=card.find('[data-widget-settings] select')
  expect(width.findAll('option').map(option=>option.attributes('value'))).toContain('3')
  expect(width.findAll('option').map(option=>option.attributes('value'))).toContain('4')
  await width.setValue('3');await flushPromises();expect(card.attributes('style')).toContain('--widget-columns: 3')
  const resize=card.find('button[aria-label="dashboardBoard.resizeWidth"]')
  await resize.trigger('pointerdown',{pointerId:1,clientX:0,clientY:0});await resize.trigger('pointermove',{pointerId:1,clientX:87,clientY:0});await resize.trigger('pointerup',{pointerId:1})
  expect(card.attributes('style')).toContain('--widget-columns: 4')
  for (const span of [5,6,7]) {
   await resize.trigger('keydown',{key:'ArrowRight'})
   expect(card.attributes('style')).toContain(`--widget-columns: ${span}`)
  }
  for (const span of [5,7,10,11]) {
   await width.setValue(String(span))
   expect(card.attributes('style')).toContain(`--widget-columns: ${span}`)
  }
  const actualDefinition=manifest.widgets.find(w=>w.type===type)!
  const available=supportedSpans(actualDefinition)
  await resize.trigger('pointerdown',{pointerId:1,clientX:0,clientY:0});await resize.trigger('pointermove',{pointerId:1,clientX:-100000,clientY:0});await resize.trigger('pointerup',{pointerId:1})
  expect(card.attributes('style')).toContain(`--widget-columns: ${available[0]}`)
  await resize.trigger('pointerdown',{pointerId:1,clientX:0,clientY:0});await resize.trigger('pointermove',{pointerId:1,clientX:100000,clientY:0});await resize.trigger('pointerup',{pointerId:1})
  expect(card.attributes('style')).toContain('--widget-columns: 12')
  expect(card.classes()).toContain('min-w-0')
  const label=card.find('.break-words');expect(label.text()).toContain(longLabel);expect(label.classes()).toContain('min-w-0');expect(label.element.parentElement?.classList.contains('flex-wrap')).toBe(true)
  wrapper.unmount()
 })
})


it('keeps the actual manifest divider fixed at twelve columns for pointer and keyboard resize', async () => {
 const raw=manifest.widgets.find(w=>w.type==='layout.divider')!
 const definition: WidgetDefinition={type:'layout.divider',titleKey:raw.titleKey,descriptionKey:raw.descriptionKey,spans:supportedSpans(raw),defaultSpan:raw.defaultSpan,options:{},repeatable:raw.repeatable,heights:raw.heights,defaultHeight:raw.defaultHeight}
 expect(definition.spans).toEqual([12])
 const instance:WidgetInstance={id:'divider',type:'layout.divider',span:12,height:'auto',options:{}}
 const wrapper=mount(WidgetResizeHandle,{props:{instance,definition,cellWidth:80,cellHeight:45}})
 await wrapper.trigger('keydown',{key:'ArrowLeft'});expect(wrapper.emitted('resize')?.at(-1)).toEqual([12,'auto'])
 await wrapper.trigger('pointerdown',{pointerId:1,clientX:0,clientY:0});await wrapper.trigger('pointermove',{pointerId:1,clientX:-100000,clientY:0})
 expect(wrapper.emitted('resize')?.at(-1)).toEqual([12,'auto']);wrapper.unmount()
})
