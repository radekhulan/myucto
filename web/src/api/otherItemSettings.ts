import { api } from './client'

export const OTHER_ITEM_DEFAULT_FOLDER = 'Ostatní pohledávky a závazky/{YYYY}/{MM}'

export interface OtherItemSettings {
  document_folder: string
  document_folder_default: string
  is_default: boolean
}

export const otherItemSettingsApi = {
  get: () => api.get<OtherItemSettings>('/accounting/other-items/settings').then(r => r.data),
  update: (documentFolder: string) =>
    api.put<OtherItemSettings>('/accounting/other-items/settings', { document_folder: documentFolder }).then(r => r.data),
}

/** Složka pro soubory dokladu: {YYYY} a {MM} z data vystavení. Bez platného data nic, nahrání jde do kořene. */
export function resolveOtherItemFolder(template: string, issuedOn: string | null | undefined): string | undefined {
  const date = issuedOn || ''
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return undefined
  const path = template.replace(/\{YYYY\}/g, date.slice(0, 4)).replace(/\{MM\}/g, date.slice(5, 7))
    .split('/').map(s => s.trim()).filter(Boolean).join('/')
  return path || undefined
}
