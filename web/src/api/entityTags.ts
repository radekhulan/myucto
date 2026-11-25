import { api } from './client'

export type TagResource = 'recurring'
export interface EntityTag { id: number; name: string }
export type TagSelection = EntityTag[] | 'none'

export function tagFilter(selection: TagSelection): string | undefined {
  return selection === 'none' ? 'none' : selection.map(tag => tag.id).join(',') || undefined
}

export async function suggestTags(resource: TagResource, q: string): Promise<EntityTag[]> {
  const { data } = await api.get<EntityTag[]>(`/${resource}/tags`, { params: { q } })
  return data
}
