import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import WidgetContent from '@/components/dashboard/layout/WidgetContent.vue'
import type { WidgetInstance, WidgetData } from '../types'
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key, te: () => false, locale: { value: 'cs' } }) }))
vi.mock('@/components/dashboard/ActionItemsWidget.vue', () => ({ default: { props: ['providedData'], template: '<div data-todo />' } }))
vi.mock('@/components/charts/PortalMonthlyChart.vue', () => ({ default: { props: ['labels','invoiced','costs','currency'], template: '<div data-chart />' } }))
vi.mock('@/components/charts/TopClientsPieChart.vue', () => ({ default: { props: ['clients'], template: '<div data-pie />' } }))
const row = (currency: string, amount: number) => ({ period: '2026-01', currency, invoiced: amount, costs: 0, profit: amount, invoiced_czk: currency === 'EUR' ? amount * 25 : amount, costs_czk: 0, profit_czk: amount, invoice_count: 1, purchase_count: 0 })
function render(type: WidgetInstance['type'], data: WidgetData, options: WidgetInstance['options'] = {}) {
  return mount(WidgetContent, { props: { instance: { id: 'test', kind: 'widget', type, span: 6, height: 'compact', options }, data }, global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } } })
}
describe('dashboard renderer', () => {
  it('keeps native currencies separate in KPI all', () => {
    const wrapper = render('business.kpi', { rows: [row('CZK',100),row('EUR',20)] }, { currency:'all',period:'ytd' })
    expect(wrapper.text()).toContain('CZK'); expect(wrapper.text()).toContain('EUR'); wrapper.unmount()
  })
  it.each(['auto', 'all'])('keeps each currency visible in monthly charts for %s', choice => {
    const wrapper = render('business.monthly', { rows: [row('EUR',20),row('CZK',100)] }, { currency:choice })
    const charts = wrapper.findAllComponents({ name: 'PortalMonthlyChart' })
    expect(charts.map(chart => chart.props('currency'))).toEqual(['EUR', 'CZK'])
    expect(charts.map(chart => chart.props('invoiced'))).toEqual([[20], [100]])
    wrapper.unmount()
  })
  it('does not hide euro receivables when currency is automatic', () => {
    const wrapper = render('receivables.aging', { rows: [{currency:'CZK',bucket:'not_due',total:100,count:1},{currency:'EUR',bucket:'overdue_30',total:20,count:1}] }, { currency:'auto' })
    expect(wrapper.text()).toContain('Kč'); expect(wrapper.text()).toContain('€'); wrapper.unmount()
  })
  it('labels an excess deduction correctly and displays a positive amount and a quarter', () => {
    const wrapper = render('vat.current',{period:'2026-Q4',period_type:'quarterly',quarter:4,tax_due:-400,is_excess_deduction:true,vat_output:0,vat_input:400})
    expect(wrapper.text()).toContain('reports.dph.excess_deduction')
    expect(wrapper.text()).toContain('crm.tax_calendar.period_quarter')
    expect(wrapper.text()).not.toContain('-400')
    wrapper.unmount()
  })
  it('does not invent list or contact links from IDs', () => {
    const wrapper = render('invoices.drafts', { items:[{ id:42, client_id:7, client_company_name:'Synthetic client', amount_to_pay:10, currency:'CZK' }] })
    expect(wrapper.findAll('a')).toHaveLength(0); wrapper.unmount()
  })
  it('uses only authorized detail and list URLs', () => {
    const wrapper = render('invoices.overdue', { items:[{ id:42, links:{ detail:'/invoices/42' } }], links:{ list:'/invoices?overdue=1' }, total:9 })
    expect(wrapper.findAll('a').map(a => a.attributes('href'))).toEqual(['/invoices/42','/invoices?overdue=1']); wrapper.unmount()
  })
  it('rejects protocol-relative and external URLs', () => {
    const wrapper = render('actions.quick', { items:[{ id:'clients', link:'//external.invalid' }], links:{ list:'https://external.invalid' } })
    expect(wrapper.findAll('a')).toHaveLength(0); wrapper.unmount()
  })
})


describe('top client table layout and CRM action labels', () => {
  const top = { items:[{client_id:1,company_name:'Synthetic client',invoice_count:3,total_czk:500,currency:'CZK',links:{client:'/clients/1'}}] }
  for (const position of ['left','right','above','below']) {
    it(`places table ${position} of the chart with consistent DOM/mobile order`, () => {
      const wrapper = render('clients.top',top,{display:'both',tablePosition:position})
      const children = wrapper.find('.clients-layout').element.children
      const tableFirst = position === 'left' || position === 'above'
      expect(children[tableFirst ? 0 : 1]?.hasAttribute('data-top-clients-table')).toBe(true)
      expect(children[tableFirst ? 1 : 0]?.hasAttribute('data-pie')).toBe(true)
      expect(wrapper.find('.clients-layout').classes().includes('clients-side')).toBe(position === 'left' || position === 'right')
      expect(wrapper.find('table tbody td').text()).toBe('Synthetic client')
      expect(wrapper.find('table a').attributes('href')).toBe('/clients/1')
      wrapper.unmount()
    })
  }
  it('supports independent table-only and chart-only displays', () => {
    const table = render('clients.top',top,{display:'table',tablePosition:'right'})
    expect(table.find('table').exists()).toBe(true); expect(table.find('[data-pie]').exists()).toBe(false); table.unmount()
    const chart = render('clients.top',top,{display:'chart',tablePosition:'above'})
    expect(chart.find('table').exists()).toBe(false); expect(chart.find('[data-pie]').exists()).toBe(true); chart.unmount()
  })
  it('passes the complete todo contract to the shared original component', () => {
    const data = { items:[{type:'journal_integrity',title:'Synthetic integrity task'}],total:1,dismissed_count:2,dismissed_types:['overdue_invoices'] }
    const wrapper = render('actions.todo',data)
    expect(wrapper.findComponent({ name:'ActionItemsWidget' }).props('providedData')).toEqual(data)
    wrapper.unmount()
  })
})
