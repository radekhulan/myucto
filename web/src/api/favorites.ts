import { api } from './client'

export interface Favorite {
  id: number
  supplier_id: number | null
  path: string
  title: string
  position: number
  created_at: string
}

export const favoritesApi = {
  list: () => api.get<Favorite[]>('/user/favorites').then(r => r.data),
  create: (p: { path: string; title: string }) => api.post<Favorite>('/user/favorites', p).then(r => r.data),
  rename: (id: number, title: string) => api.put<Favorite>(`/user/favorites/${id}`, { title }).then(r => r.data),
  remove: (id: number) => api.delete<{ deleted: boolean }>(`/user/favorites/${id}`).then(r => r.data),
}
