import manifest from '../../../api/resources/dashboard/layouts.json'
import type { BoardId, DashboardLayout, WidgetGeometry } from './types'
export function sectionsFor(board: BoardId) { return manifest.sections[board] }
export function geometryFor(type: string): WidgetGeometry | undefined { return (manifest.widgets as Record<string, WidgetGeometry>)[type] }
export function defaultLayout(board: BoardId): DashboardLayout {
  return { schemaVersion: 2, items: sectionsFor(board).map(section => ({ id: `section-${section.type}`, kind: 'section', type: section.type, span: 12, height: 'auto', options: {} })) }
}
