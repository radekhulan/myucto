import type { PortalKpiRow, PortalForecast, PortalVatStatus } from '@/api/portal'
import type { TaxAnalysis } from '@/api/tax'
export type BoardId = 'home' | 'portal'
export type WidgetOptions = Record<string, string | number | boolean>
export type WidgetType = string
export type WidgetHeight = 'auto' | 'compact' | 'normal' | 'tall'
export interface LayoutItem { id: string; kind: 'section' | 'widget' | 'divider'; type: string; span: number; height: WidgetHeight; options: WidgetOptions }
export type WidgetInstance = LayoutItem
export interface DashboardLayout { schemaVersion: 2; items: LayoutItem[] }
export interface OptionDefinition { type: string; values?: (string | number | boolean)[]; default: string | number | boolean; pattern?: string }
export interface WidgetDefinition { type: string; titleKey: string; descriptionKey: string; options: Record<string, OptionDefinition>; capabilities?: Record<string, boolean> }
export interface WidgetCatalog { board: BoardId; widgets: WidgetDefinition[] }
export interface LayoutResponse { layout: DashboardLayout | null; revision: string; recovered: boolean; writable?: boolean }
export interface WidgetItem { [key: string]: unknown; id?: number | string; type?: string; title?: string; titleKey?: string; label?: string; link?: string; links?: Record<string, string>; currency?: string; count?: number; amount?: number; total?: number }
export interface WidgetData { rows?: (PortalKpiRow | WidgetItem)[]; items?: WidgetItem[]; links?: Record<string, string>; period?: string; analysis?: TaxAnalysis; status?: PortalVatStatus; currency?: string; weeks?: PortalForecast['weeks']; [key: string]: unknown }
export interface WidgetResult { status: 'ok' | 'empty' | 'unavailable' | 'error'; data?: WidgetData | null }
export interface QueryResponse { widgets: Record<string, WidgetResult> }
export interface WidgetGeometry { minSpan: number; maxSpan: number; defaultSpan: number; heights: WidgetHeight[]; defaultHeight: WidgetHeight }
export function cloneLayout(layout: DashboardLayout): DashboardLayout { return JSON.parse(JSON.stringify(layout)) as DashboardLayout }

/** Vkládání funguje před libovolnou položku i na konec prázdné skupiny. */
export function moveBefore(layout: DashboardLayout, id: string, beforeId: string | null): void {
  if (id === beforeId) return
  const from = layout.items.findIndex(item => item.id === id)
  if (from < 0 || (beforeId !== null && !layout.items.some(item => item.id === beforeId))) return
  const [item] = layout.items.splice(from, 1)
  if (item) layout.items.splice(beforeId === null ? layout.items.length : layout.items.findIndex(row => row.id === beforeId), 0, item)
}
