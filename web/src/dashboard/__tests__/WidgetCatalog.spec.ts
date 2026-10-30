import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import WidgetCatalog from '@/components/dashboard/board/WidgetCatalog.vue'
import manifest from '../../../shared/dashboard-widgets.json'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import { supportedSpans, type WidgetDefinition, type WidgetType } from '../types'
const definitions: WidgetDefinition[]=manifest.widgets.filter(w=>w.type!=='layout.divider').map(w=>({type:w.type as WidgetType,titleKey:w.titleKey,descriptionKey:w.descriptionKey,spans:supportedSpans(w),defaultSpan:w.defaultSpan,repeatable:w.repeatable,options:{}}))
function catalog(locale:'cs'|'en'){
 const i18n=createI18n({legacy:false,locale,messages:{cs:{dashboardWidgets:cs.dashboardWidgets,dashboardBoard:cs.dashboardBoard,common:{search:cs.common.search}},en:{dashboardWidgets:en.dashboardWidgets,dashboardBoard:en.dashboardBoard,common:{search:en.common.search}}}})
 return {wrapper:mount(WidgetCatalog,{props:{definitions},global:{plugins:[i18n]}}),i18n}
}
describe('catalog search with actual Czech and English messages',()=>{
 for(const locale of ['cs','en'] as const){
  it(`filters translated titles case insensitively in ${locale}`,async()=>{
   const {wrapper}=catalog(locale);const messages=locale==='cs'?cs:en
   const title=messages.dashboardWidgets.widgets.business_monthly.title
   await wrapper.find('input[type="search"]').setValue(title.toLocaleUpperCase())
   expect(wrapper.find('[data-widget-catalog-item="business.monthly"]').exists()).toBe(true)
   expect(wrapper.find('[data-widget-catalog-item="actions.quick"]').exists()).toBe(false)
   expect(wrapper.findAll('[data-widget-catalog-item]').length).toBeLessThan(definitions.length)
   await wrapper.find('input').setValue('');expect(wrapper.findAll('[data-widget-catalog-item]')).toHaveLength(definitions.length);wrapper.unmount()
  })
  it(`finds translated descriptions and emits the original definition in ${locale}`,async()=>{
   const {wrapper}=catalog(locale);const messages=locale==='cs'?cs:en
   await wrapper.find('input').setValue(messages.dashboardWidgets.widgets.cashflow_collections.description)
   const card=wrapper.find('[data-widget-catalog-item="cashflow.collections"]');expect(card.exists()).toBe(true)
   await card.find('button').trigger('click');expect(wrapper.emitted('add')?.[0]?.[0]).toEqual(definitions.find(d=>d.type==='cashflow.collections'));wrapper.unmount()
  })
 }
 it('re-evaluates translations and language when the active locale changes',async()=>{
  const {wrapper,i18n}=catalog('cs');await wrapper.find('input').setValue(cs.dashboardWidgets.widgets.business_monthly.title)
  expect(wrapper.find('[data-widget-catalog-item="business.monthly"]').exists()).toBe(true)
  i18n.global.locale.value='en';await wrapper.vm.$nextTick();expect(wrapper.attributes('lang')).toBe('en')
  await wrapper.find('input').setValue(en.dashboardWidgets.widgets.business_monthly.title);expect(wrapper.find('[data-widget-catalog-item="business.monthly"]').exists()).toBe(true);wrapper.unmount()
 })
 it('keeps disabled catalog actions from emitting add',async()=>{
  const {wrapper}=catalog('en');await wrapper.setProps({disabled:true});await wrapper.find('button').trigger('click');expect(wrapper.emitted('add')).toBeUndefined();wrapper.unmount()
 })
})
