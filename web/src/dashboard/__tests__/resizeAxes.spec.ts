import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import WidgetResizeHandle from '@/components/dashboard/board/WidgetResizeHandle.vue'
import type { WidgetDefinition, WidgetInstance } from '../types'
vi.mock('vue-i18n',()=>({useI18n:()=>({t:(key:string)=>key})}))
const definition: WidgetDefinition={type:'business.monthly',titleKey:'monthly',descriptionKey:'monthly',spans:Array.from({length:9},(_,i)=>i+4),defaultSpan:6,heights:['auto','compact','normal','tall'],defaultHeight:'auto',repeatable:false,options:{}}
const instance: WidgetInstance={id:'monthly',type:'business.monthly',span:6,height:'normal',options:{}}
function handle(axis:'width'|'height'|'both',height:WidgetInstance['height']='normal') {return mount(WidgetResizeHandle,{props:{axis,instance:{...instance,height},definition,cellWidth:80,cellHeight:45}})}
describe('independent widget resize axes',()=>{
 it.each(['auto','tall',undefined] as const)('width drag preserves exact original height %s despite large vertical motion',async height=>{
  const wrapper=handle('width');await wrapper.setProps({instance:{...instance,height}})
  await wrapper.trigger('pointerdown',{pointerId:1,clientX:0,clientY:0});await wrapper.trigger('pointermove',{pointerId:1,clientX:96,clientY:-100000})
  expect(wrapper.emitted('resize')?.at(-1)).toEqual([7,height])
  await wrapper.trigger('pointermove',{pointerId:1,clientX:192,clientY:100000});expect(wrapper.emitted('resize')?.at(-1)).toEqual([8,height]);wrapper.unmount()
 })
 it('height drag preserves original column span despite large horizontal motion',async()=>{
  const wrapper=handle('height','auto')
  await wrapper.trigger('pointerdown',{pointerId:1,clientX:0,clientY:0});await wrapper.trigger('pointermove',{pointerId:1,clientX:100000,clientY:100000})
  expect(wrapper.emitted('resize')?.at(-1)).toEqual([6,'tall'])
  await wrapper.trigger('pointermove',{pointerId:1,clientX:-100000,clientY:-100000});expect(wrapper.emitted('resize')?.at(-1)).toEqual([6,'compact']);wrapper.unmount()
 })
 it('width keyboard ignores vertical arrows without emitting and keeps automatic height',async()=>{
  const wrapper=handle('width','auto');await wrapper.trigger('keydown',{key:'ArrowDown'});await wrapper.trigger('keydown',{key:'ArrowUp'});expect(wrapper.emitted('resize')).toBeUndefined()
  await wrapper.trigger('keydown',{key:'ArrowRight'});expect(wrapper.emitted('resize')?.at(-1)).toEqual([7,'auto']);await wrapper.trigger('keydown',{key:'ArrowLeft'});expect(wrapper.emitted('resize')?.at(-1)).toEqual([5,'auto']);wrapper.unmount()
 })
 it('height keyboard ignores horizontal arrows without emitting',async()=>{
  const wrapper=handle('height');await wrapper.trigger('keydown',{key:'ArrowRight'});await wrapper.trigger('keydown',{key:'ArrowLeft'});expect(wrapper.emitted('resize')).toBeUndefined()
  await wrapper.trigger('keydown',{key:'ArrowDown'});expect(wrapper.emitted('resize')?.at(-1)).toEqual([6,'tall']);await wrapper.trigger('keydown',{key:'ArrowUp'});expect(wrapper.emitted('resize')?.at(-1)).toEqual([6,'compact']);wrapper.unmount()
 })
 it('corner retains simultaneous resizing and axis-specific accessible placement',async()=>{
  const wrapper=handle('both');await wrapper.trigger('pointerdown',{pointerId:1,clientX:0,clientY:0});await wrapper.trigger('pointermove',{pointerId:1,clientX:192,clientY:100000});expect(wrapper.emitted('resize')?.at(-1)).toEqual([8,'tall']);expect(wrapper.classes()).toContain('cursor-nwse-resize');wrapper.unmount()
  const width=handle('width');expect(width.attributes('aria-label')).toBe('dashboardBoard.resizeWidth');expect(width.classes()).toContain('top-1/2');expect(width.classes()).toContain('cursor-ew-resize');expect(width.find('.resize-edge-mark').exists()).toBe(true);expect(width.find('svg').exists()).toBe(false);width.unmount()
  const height=handle('height');expect(height.attributes('aria-label')).toBe('dashboardBoard.resizeHeight');expect(height.classes()).toContain('left-1/2');expect(height.classes()).toContain('cursor-ns-resize');expect(height.find('.resize-edge-mark').exists()).toBe(true);expect(height.find('svg').exists()).toBe(false);height.unmount()
 })
 it('releases capture on cancellation, disabling, and unmount without further resize events',async()=>{
  const wrapper=handle('width');const release=vi.fn()
  Object.assign(wrapper.element,{setPointerCapture:vi.fn(),hasPointerCapture:()=>true,releasePointerCapture:release})
  await wrapper.trigger('pointerdown',{pointerId:1});await wrapper.trigger('pointercancel',{pointerId:1});await wrapper.trigger('pointermove',{pointerId:1,clientX:500});expect(wrapper.emitted('resize')).toBeUndefined();expect(release).toHaveBeenCalledTimes(1)
  await wrapper.trigger('pointerdown',{pointerId:2});await wrapper.setProps({disabled:true});await wrapper.trigger('pointermove',{pointerId:2,clientX:500});expect(wrapper.emitted('resize')).toBeUndefined();expect(release).toHaveBeenCalledTimes(2)
  await wrapper.setProps({disabled:false});await wrapper.trigger('pointerdown',{pointerId:3});wrapper.unmount();expect(release).toHaveBeenCalledTimes(3)
 })
})
