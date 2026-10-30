import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import DashboardBoard from '@/components/dashboard/board/DashboardBoard.vue'
vi.mock('vue-i18n',async original=>({...await original<typeof import('vue-i18n')>(),useI18n:()=>({t:(key:string)=>key,te:()=>false,locale:{value:'cs'}})}))
vi.mock('@/stores/auth',()=>({useAuthStore:()=>({user:{id:1},permissions:{},license:{}})}))
vi.mock('@/stores/supplier',()=>({useSupplierStore:()=>({currentSupplierId:1,hasSupplier:true,currentSupplier:{id:1}})}))
vi.mock('@/composables/useWindowRefresh',()=>({useWindowRefresh:()=>({})}))
vi.mock('@/dashboard/api',()=>{
 const widgets=[{id:'a',type:'business.kpi',span:6,height:'auto',options:{}},{id:'separator',type:'layout.divider',span:12,height:'auto',options:{}},{id:'b',type:'business.kpi',span:6,height:'auto',options:{}}]
 const layout={schemaVersion:1,presetVersion:1,widgets}
 return {dashboardBoardApi:{catalog:()=>Promise.resolve({board:'home',widgets:[{type:'business.kpi',titleKey:'kpi',descriptionKey:'kpi',spans:[6,12],defaultSpan:6,repeatable:true,options:{}},{type:'layout.divider',titleKey:'divider',descriptionKey:'divider',spans:[12],defaultSpan:12,repeatable:true,options:{}}],defaultLayout:layout}),layout:()=>Promise.resolve({layout,revision:'r1'}),query:()=>Promise.resolve({widgets:{a:{status:'empty'},b:{status:'empty'}}}),save:vi.fn(),reset:vi.fn()}}
})
async function board(){const wrapper=mount(DashboardBoard,{props:{board:'home'}});await flushPromises();await wrapper.findAll('button').find(b=>b.text().includes('dashboardBoard.edit'))!.trigger('click');return wrapper}
const order=(wrapper:Awaited<ReturnType<typeof board>>)=>wrapper.findAll('[data-drop-id]').map(e=>e.attributes('data-drop-id'))
async function drag(wrapper:Awaited<ReturnType<typeof board>>,source:string,target:string,cancel=false){
 for(const [index,element] of wrapper.findAll('[data-drop-id]').entries()) vi.spyOn(element.element,'getBoundingClientRect').mockReturnValue({left:0,right:200,top:index*150,bottom:index*150+100,width:200,height:100,x:0,y:index*150,toJSON:()=>({})})
 const targetIndex=order(wrapper).indexOf(target)
 const grip=wrapper.find(`[data-drop-id="${source}"] button[aria-label="dashboardBoard.move"]`)
 await grip.trigger('pointerdown',{pointerId:1,clientX:20,clientY:20})
 await grip.trigger('pointermove',{pointerId:1,clientX:source === 'a' && target === 'b' ? 150 : 50,clientY:targetIndex*150+50})
 expect(wrapper.find(`[data-drop-id="${target}"]`).classes().some(c=>c.includes('ring'))).toBe(true)
 await grip.trigger(cancel?'pointercancel':'pointerup',{pointerId:1});await flushPromises()
}
describe('captured pointer drag handles',()=>{
 it('moves a widget to another widget across an invisible divider',async()=>{const wrapper=await board();await drag(wrapper,'a','b');expect(order(wrapper)).toEqual(['separator','b','a']);wrapper.unmount()})
 it('moves a widget across the divider using its same explicit grip',async()=>{const wrapper=await board();await drag(wrapper,'b','separator');expect(order(wrapper)).toEqual(['a','b','separator']);wrapper.unmount()})
 it('moves a divider before a widget with the same grip',async()=>{const wrapper=await board();await drag(wrapper,'separator','a');expect(order(wrapper)).toEqual(['separator','a','b']);wrapper.unmount()})
 it('cancels without changing order or leaving a target highlight',async()=>{const wrapper=await board();await drag(wrapper,'a','b',true);expect(order(wrapper)).toEqual(['a','separator','b']);expect(wrapper.findAll('[data-drop-target="true"]')).toHaveLength(0);wrapper.unmount()})
})
