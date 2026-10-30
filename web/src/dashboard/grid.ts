import type { WidgetInstance } from './types'
export interface DashboardGroup { id: string; divider?: WidgetInstance; widgets: WidgetInstance[] }
/** Oddělovač ukončí mřížku; další skupina začíná až pod její nejvyšší kartou. */
export function groupWidgets(widgets: WidgetInstance[], editing = false): DashboardGroup[] {
  const groups: DashboardGroup[] = [{ id: 'first', widgets: [] }]
  for (const instance of widgets) {
    if (instance.type === 'layout.divider') groups.push({ id: instance.id, divider: instance, widgets: [] })
    else groups[groups.length - 1]!.widgets.push(instance)
  }
  return groups.filter(group => group.widgets.length || (editing && group.divider))
}
export function cellHeightForWidth(width: number, gap = 16): number { return Math.max(1, (width - 11 * gap) / 12 * 9 / 16) }
export function rowsForContent(height: number, cellHeight: number, preset: WidgetInstance['height'], gap = 16): number {
  return Math.max(({ auto: 1, compact: 4, normal: 6, tall: 9 })[preset ?? 'auto'], Math.ceil((height + 2 + gap) / (cellHeight + gap)))
}
