import { api } from './client'

export interface SavedFilter {
  id: number
  page_key: string                       // snake_case, 1:1 s BE whitelistem (§3.4)
  name: string
  is_default: boolean
  sort_order: number
  payload: Record<string, string>        // plochý snapshot URL query z buildQuery()
  updated_at: string
}

export interface SortPref { key: string; dir: 'asc' | 'desc' }

export interface TablePrefs {
  column_colors?: Record<string, string> | null
  column_order?: string[] | null
  hidden?: string[] | null               // SKRYTÉ sloupce (R9); absence/null = default stránky
  shown?: string[] | null                // explicitně ODKRYTÉ doplňkové (defaultHidden) sloupce
  sort?: SortPref | null
  density?: 'comfortable' | 'compact'
  // Lepkavé přepínače pohledu, které nejsou filtrem dat, ale způsobem zobrazení
  // (dnes `analytics` = rozpad po analytikách v hlavní knize a předvaze). Ukládají
  // se sem, ne mezi filtry: účetní si je nastaví jednou a čeká je i příště.
  flags?: Record<string, boolean> | null
  // Uložená nastavení stránky, která nejsou jen ano/ne (např. hranice členění PDF saldokonta).
  options?: Record<string, unknown> | null
}

export interface NavOrderPrefs {
  sections?: string[]                     // pořadí bloků (klíče sekcí)
  items?: Record<string, string[]>        // pořadí položek uvnitř bloku (sectionKey → item.to[])
  hidden?: string[]                       // položky skryté uživatelem
}

/**
 * Předvolby zobrazení (`display.defaults`) — výchozí podoba sekcí a přepínačů napříč
 * stránkami. Ruční změna na stránce je dál věcí té stránky; tohle je jen výchozí stav.
 */
export interface DisplayPrefs {
  /** Sekce Zaúčtování v detailu dokladů rozbalená. */
  posting_open?: boolean
  /** Sekce Dimenze v detailu dokladů rozbalená. */
  dimensions_open?: boolean
  /** Náhled originálu (PDF) v detailu a editoru dokladu otevírat automaticky. */
  preview_open?: boolean
  preview_placement?: 'side' | 'below'
  /** Výkazy po účtech: skrýt nulové účty / zobrazit analytiky. */
  statement_hide_zero?: boolean
  statement_analytics?: boolean
  /** Jednotky rozvahy a výsledovky. */
  statement_unit?: 'czk' | 'thousands'
  /** Odhad do konce roku: rozpad rozbalený. */
  estimate_open?: boolean
  /** Výkazy: průběžné časové rozlišení a odpisy zapnuté, dokud ho uživatel na výkazu sám nepřepne. */
  statement_accrual_spread?: boolean
}

export const preferencesApi = {
  // filtry: jeden request na session — bez page_key vrací vše pro usera + aktuální firmu
  listFilters:  () => api.get<SavedFilter[]>('/user/filters').then(r => r.data),
  createFilter: (p: { page_key: string; name: string; payload: Record<string, string>; is_default?: boolean }) =>
                  api.post<SavedFilter>('/user/filters', p).then(r => r.data),
  updateFilter: (id: number, p: Partial<Pick<SavedFilter, 'name' | 'payload' | 'sort_order' | 'is_default'>>) =>
                  api.put<SavedFilter>(`/user/filters/${id}`, p).then(r => r.data),
  deleteFilter: (id: number) => api.delete<{ deleted: boolean }>(`/user/filters/${id}`).then(r => r.data),

  // preference: mapa pref_key → payload
  getPreferences: () => api.get<Record<string, TablePrefs>>('/user/preferences').then(r => r.data),
  getPreferenceKey: <T>(key: string) =>
                    api.get<Record<string, T>>('/user/preferences', { params: { keys: key } }).then(r => r.data[key]),
  putPreference:  (pageKey: string, prefs: TablePrefs) =>
                    api.put<TablePrefs>(`/user/preferences/table.${pageKey}`, prefs).then(r => r.data),
  deletePreference: (pageKey: string) =>
                    api.delete<{ deleted: boolean }>(`/user/preferences/table.${pageKey}`).then(r => r.data),

  // varianty pro plný pref_key (např. nav.order) — bez skládání `table.` prefixu (§10)
  putPreferenceKey: <T>(key: string, payload: T) =>
                    api.put<T>(`/user/preferences/${key}`, payload).then(r => r.data),
  deletePreferenceKey: (key: string) =>
                    api.delete<{ deleted: boolean }>(`/user/preferences/${key}`).then(r => r.data),
}
