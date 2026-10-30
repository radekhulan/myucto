import { describe, expect, it } from 'vitest'
import { cellHeightForWidth, groupWidgets, rowsForContent } from '../grid'
import { moveBefore, visibleInstances, type DashboardLayout, type WidgetCatalog, type WidgetInstance } from '../types'
const widget = (id: string, type: WidgetInstance['type'] = 'business.kpi'): WidgetInstance => ({ id, type, span: type === 'layout.divider' ? 12 : 3, options: {} })
describe('dashboard 16:9 cells and invisible row separators', () => {
  it('derives cell height from twelve columns after subtracting eleven gaps', () => { expect(cellHeightForWidth(1376)).toBe(56.25) })
  it('grows auto rows to contain late or multiline data with no overlap', () => {
    expect(rowsForContent(160,40,'auto')).toBe(4)
    expect(rowsForContent(610,40,'auto')).toBe(12)
    expect(rowsForContent(610,40,'compact')).toBe(12)
    expect(rowsForContent(160,40,'tall')).toBe(9)
  })
  it('creates independent grids on each divider and preserves widget order', () => {
    const groups = groupWidgets([widget('a'),widget('break','layout.divider'),widget('b'),widget('c')])
    expect(groups.map(g => g.widgets.map(w => w.id))).toEqual([['a'],['b','c']])
  })
  it('collapses unavailable-only groups without leaving extra runtime whitespace', () => {
    const layout: DashboardLayout = { schemaVersion:1,presetVersion:1,widgets:[widget('leading','layout.divider'),widget('hidden','accounting.status'),widget('break','layout.divider'),widget('a'),widget('trailing','layout.divider')] }
    const catalog = { widgets:[{type:'business.kpi'},{type:'layout.divider'}] } as WidgetCatalog
    expect(groupWidgets(visibleInstances(layout,catalog)).map(g => g.widgets.map(w => w.id))).toEqual([['a']])
    expect(layout.widgets).toHaveLength(5)
  })
  it('shows empty separators in the editor so they remain movable and removable', () => {
    expect(groupWidgets([widget('a'),widget('break','layout.divider'),widget('second','layout.divider')],true)).toHaveLength(3)
  })
})


it('uses explicit insertion anchors while preserving hidden instances and divider order', () => {
  const layout: DashboardLayout = { schemaVersion:1,presetVersion:1,widgets:[widget('quick'),widget('hidden','accounting.status'),widget('divider','layout.divider'),widget('todo'),widget('vat')] }
  moveBefore(layout,'todo','divider')
  expect(layout.widgets.map(w=>w.id)).toEqual(['quick','hidden','todo','divider','vat'])
  moveBefore(layout,'quick',null)
  expect(layout.widgets.map(w=>w.id)).toEqual(['hidden','todo','divider','vat','quick'])
})
