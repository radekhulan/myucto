import { describe, expect, it } from 'vitest'
import { hasBusinessActivity } from '../activity'
import type { WidgetInstance, WidgetResult } from '../types'
const instance = (id: string,type: WidgetInstance['type']): WidgetInstance => ({ id,type,span:12,options:{} })
describe('dashboard onboarding activity', () => {
  it('keeps a new company empty despite quick actions, todos and deadlines', () => {
    const instances = [instance('quick','actions.quick'),instance('todo','actions.todo'),instance('calendar','tax.calendar'),instance('kpi','business.kpi')]
    const results: Record<string, WidgetResult> = { quick:{status:'ok',data:{items:[{id:'invoices',link:'/invoices/new'}]}},todo:{status:'ok',data:{items:[{type:'bank_unmatched'}]}},calendar:{status:'ok',data:{items:[{title:'Synthetic deadline'}]}},kpi:{status:'ok',data:{rows:[{currency:'CZK',invoice_count:0,purchase_count:0,invoiced:0,costs:0}]}} }
    expect(hasBusinessActivity(results,instances)).toBe(false)
  })
  it('recognizes a company with only purchases, including the legacy older-purchase flag', () => {
    expect(hasBusinessActivity({p:{status:'ok',data:{purchase_count_ytd:1}}},[instance('p','purchases.summary')])).toBe(true)
    expect(hasBusinessActivity({p:{status:'ok',data:{purchase_count_ytd:0,has_purchase_invoices:true}}},[instance('p','purchases.summary')])).toBe(true)
    expect(hasBusinessActivity({k:{status:'ok',data:{rows:[{purchase_count:1,costs:25}]}}},[instance('k','business.kpi')])).toBe(true)
  })
})
