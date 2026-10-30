import type { PortalKpiRow, PortalForecast, PortalVatStatus } from '@/api/portal'
import type { TaxAnalysis } from '@/api/tax'
export type BoardId = 'home' | 'portal'
export const widgetTypes = ['layout.divider','business.kpi','business.monthly','sales.summary','purchases.summary','receivables.aging','payables.aging','cashflow.weekly','cashflow.collections','receivables.due','invoices.overdue','invoices.upcoming','clients.top','invoices.drafts','invoices.approvals','documents.requests','actions.quick','actions.todo','vat.current','tax.deadlines','tax.calendar','tax.net','tax.advances','accounting.status'] as const
export type WidgetType = typeof widgetTypes[number]
export type WidgetOptions = Record<string, string | number | boolean>
export interface WidgetInstance { id: string; type: WidgetType; span: number; height?: 'auto' | 'compact' | 'normal' | 'tall'; options: WidgetOptions }
export interface DashboardLayout { schemaVersion: 1; presetVersion: number; widgets: WidgetInstance[] }
export interface OptionDefinition { type: string; values?: (string | number | boolean)[]; default: string | number | boolean; pattern?: string }
export interface WidgetDefinition { type: WidgetType; titleKey: string; descriptionKey: string; spans: number[]; minSpan?: number; maxSpan?: number; defaultSpan: number; options: Record<string, OptionDefinition>; repeatable: boolean; heights?: string[]; defaultHeight?: string; emptyState?: 'show' | 'hide' }
export interface WidgetCatalog { board: BoardId; widgets: WidgetDefinition[]; defaultLayout: DashboardLayout; businessActivity?: { hasDocuments: boolean } }
export interface LayoutResponse { layout: DashboardLayout | null; revision: string }
export interface WidgetItem { [key: string]: unknown; id?: number | string; type?: string; title?: string; titleKey?: string; label?: string; link?: string; links?: Record<string, string>; currency?: string; count?: number; amount?: number; total?: number }
export interface WidgetData { rows?: (PortalKpiRow | WidgetItem)[]; items?: WidgetItem[]; links?: Record<string, string>; period?: string; analysis?: TaxAnalysis; status?: PortalVatStatus; currency?: string; weeks?: PortalForecast['weeks']; [key: string]: unknown }
export interface WidgetResult { status: 'ok' | 'empty' | 'unavailable' | 'error'; data?: WidgetData }
export interface QueryResponse { widgets: Record<string, WidgetResult>; today: string; currency: string; businessActivity?: { hasDocuments: boolean } }
export function cloneLayout(layout: DashboardLayout): DashboardLayout { return JSON.parse(JSON.stringify(layout)) as DashboardLayout }
export function visibleInstances(layout: DashboardLayout | null, catalog: WidgetCatalog | null): WidgetInstance[] {
  const allowed = new Set(catalog?.widgets.map(w => w.type) ?? [])
  return layout?.widgets.filter(w => allowed.has(w.type)) ?? []
}
export function moveInstance(layout: DashboardLayout, id: string, targetId: string): void {
  const from = layout.widgets.findIndex(w => w.id === id)
  const to = layout.widgets.findIndex(w => w.id === targetId)
  if (from < 0 || to < 0 || from === to) return
  const [item] = layout.widgets.splice(from, 1)
  if (item) layout.widgets.splice(to, 0, item)
}
/** Přesný bod vložení nezávisí na původním indexu přesouvané položky. */
export function moveBefore(layout: DashboardLayout, id: string, beforeId: string | null): void {
  if (id === beforeId) return
  const from = layout.widgets.findIndex(widget => widget.id === id)
  if (from < 0 || (beforeId !== null && !layout.widgets.some(widget => widget.id === beforeId))) return
  const [item] = layout.widgets.splice(from, 1)
  if (!item) return
  const target = beforeId === null ? layout.widgets.length : layout.widgets.findIndex(widget => widget.id === beforeId)
  layout.widgets.splice(target, 0, item)
}

/** Manifest popisuje rozsah, katalog poskytuje odvozené šířky po jednom sloupci. */
export function supportedSpans(definition: { minSpan?: number; maxSpan?: number; spans?: number[] }): number[] {
  if (definition.minSpan !== undefined && definition.maxSpan !== undefined) return Array.from({ length: definition.maxSpan - definition.minSpan + 1 }, (_, index) => definition.minSpan! + index)
  return definition.spans ?? []
}
