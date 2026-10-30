import { api } from '@/api/client'
import type { BoardId, DashboardLayout, LayoutResponse, QueryResponse, WidgetCatalog, WidgetInstance } from './types'
export const dashboardBoardApi = {
  catalog: (board: BoardId) => api.get<WidgetCatalog>('/dashboard-widgets/catalog', { params: { board } }).then(r => r.data),
  layout: (board: BoardId) => api.get<LayoutResponse>(`/user/dashboard-layouts/${board}`).then(r => r.data),
  save: (board: BoardId, layout: DashboardLayout, revision: string) => api.put<LayoutResponse>(`/user/dashboard-layouts/${board}`, { layout, revision }).then(r => r.data),
  reset: (board: BoardId, revision: string) => api.delete<LayoutResponse>(`/user/dashboard-layouts/${board}`, { data: { revision } }).then(r => r.data),
  query: (board: BoardId, widgets: WidgetInstance[]) => api.post<QueryResponse>('/dashboard-widgets/query', { board, widgets }).then(r => r.data),
}
