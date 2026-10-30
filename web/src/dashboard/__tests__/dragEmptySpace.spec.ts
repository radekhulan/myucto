import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
const scenario=vi.hoisted(()=>({trailing:false}))
import DashboardBoard from '@/components/dashboard/board/DashboardBoard.vue'
vi.mock('vue-i18n',async original=>({...await original<typeof import('vue-i18n')>(),useI18n:()=>({t:(key:string)=>key,te:()=>false,locale:{value:'cs'}})}))
vi.mock('@/stores/auth',()=>({useAuthStore:()=>({user:{id:1},permissions:{},license:{}})}))
vi.mock('@/stores/supplier',()=>({useSupplierStore:()=>({currentSupplierId:1,hasSupplier:true,currentSupplier:{id:1}})}))
vi.mock('@/composables/useWindowRefresh',()=>({useWindowRefresh:()=>({})}))
vi.mock('@/dashboard/api',()=>{
 const widgets=[{id:'quick',type:'actions.quick',span:6,height:'auto',options:{}},{id:'divider',type:'layout.divider',span:12,height:'auto',options:{}},{id:'todo',type:'actions.todo',span:6,height:'auto',options:{}},{id:'vat',type:'vat.current',span:6,height:'auto',options:{}}]
 const layout={schemaVersion:1,presetVersion:1,widgets}
 return {dashboardBoardApi:{catalog:()=>Promise.resolve({board:'home',widgets:widgets.map(w=>({type:w.type,titleKey:w.type,descriptionKey:w.type,spans:[6,12],defaultSpan:6,repeatable:false,options:{}})),defaultLayout:layout}),layout:()=>Promise.resolve({layout:{...layout,widgets:scenario.trailing?[...layout.widgets,{id:'tail',type:'layout.divider',span:12,height:'auto',options:{}}]:layout.widgets},revision:'r1'}),query:()=>Promise.resolve({widgets:{quick:{status:'empty'},todo:{status:'empty'},vat:{status:'empty'}}}),save:vi.fn(),reset:vi.fn()}}
})
function rectangle(left:number,top:number,width:number,height:number):DOMRect{return{left,top,right:left+width,bottom:top+height,width,height,x:left,y:top,toJSON:()=>({})}}
describe('dropping into unused dashboard columns',()=>{
 for(const scrollOffset of [0,100]) it(`inserts todo in first group before the next divider using client coordinates (scroll ${scrollOffset})`,async()=>{
  const wrapper=mount(DashboardBoard,{props:{board:'home'}});await flushPromises();await wrapper.findAll('button').find(b=>b.text().includes('dashboardBoard.edit'))!.trigger('click')
  const positions={quick:rectangle(0,0-scrollOffset,500,200),divider:rectangle(0,215-scrollOffset,1000,30),todo:rectangle(0,250-scrollOffset,500,200),vat:rectangle(500,250-scrollOffset,500,200)}
  for(const [id,rect] of Object.entries(positions))vi.spyOn(wrapper.find(`[data-drop-id="${id}"]`).element,'getBoundingClientRect').mockReturnValue(rect)
  vi.spyOn(wrapper.find('[data-group-dropzone="first"]').element,'getBoundingClientRect').mockReturnValue(rectangle(0,0-scrollOffset,1000,200))
  vi.spyOn(wrapper.find('[data-group-dropzone="divider"]').element,'getBoundingClientRect').mockReturnValue(rectangle(0,250-scrollOffset,1000,200))
  const grip=wrapper.find('[data-drop-id="todo"] button[aria-label="dashboardBoard.move"]')
  await grip.trigger('pointerdown',{pointerId:1,clientX:250,clientY:350-scrollOffset})
  await grip.trigger('pointermove',{pointerId:1,clientX:750,clientY:100-scrollOffset})
  expect(wrapper.find('[data-group-dropzone="first"]').classes()).toContain('ring-2')
  await grip.trigger('pointerup',{pointerId:1});await flushPromises()
  expect(wrapper.findAll('[data-drop-id]').map(e=>e.attributes('data-drop-id'))).toEqual(['quick','todo','divider','vat'])
  const divider=wrapper.find('[data-drop-id="divider"]');expect(divider.classes()).toContain('w-full');expect(divider.classes()).not.toContain('absolute')
  expect(wrapper.find('[data-group-dropzone="first"]').classes()).not.toContain('ring-2');wrapper.unmount()
 })
})


it('gives a trailing empty group an explicit editor-only drop height and accepts a widget inside it',async()=>{
 scenario.trailing=true
 const wrapper=mount(DashboardBoard,{props:{board:'home'}});await flushPromises()
 expect(wrapper.find('[data-group-dropzone="tail"]').exists()).toBe(false)
 await wrapper.findAll('button').find(b=>b.text().includes('dashboardBoard.edit'))!.trigger('click')
 const zone=wrapper.find<HTMLElement>('[data-group-dropzone="tail"]')
 expect(zone.findAll('article')).toHaveLength(0)
 expect(zone.element.style.minHeight).toBe('96px')
 const editorDropHeight=Number.parseFloat(zone.element.style.minHeight)
 for(const element of wrapper.findAll('[data-drop-id]'))vi.spyOn(element.element,'getBoundingClientRect').mockReturnValue(rectangle(0,0,0,0))
 vi.spyOn(zone.element,'getBoundingClientRect').mockReturnValue(rectangle(0,500,1000,editorDropHeight))
 const grip=wrapper.find('[data-drop-id="vat"] button[aria-label="dashboardBoard.move"]')
 await grip.trigger('pointerdown',{pointerId:1,clientX:250,clientY:350})
 await grip.trigger('pointermove',{pointerId:1,clientX:750,clientY:500+editorDropHeight/2})
 expect(zone.classes()).toContain('ring-2')
 await grip.trigger('pointerup',{pointerId:1});await flushPromises()
 expect(wrapper.findAll('[data-drop-id]').map(e=>e.attributes('data-drop-id'))).toEqual(['quick','divider','todo','tail','vat'])
 expect(wrapper.find('[data-group-dropzone="tail"]').attributes('style') ?? '').not.toContain('min-height')
 wrapper.unmount();scenario.trailing=false
})
