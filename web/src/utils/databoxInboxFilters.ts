import type { InboxBrowseParams, InboxClassification } from '@/api/dataBox'

/**
 * Stav filtrů seznamu příchozích zpráv datové schránky a jeho zápis do URL.
 *
 * Filtry žijí v query stringu, aby šel odkaz na „nepřečtené od finančního
 * úřadu za březen" poslat kolegovi nebo uložit do záložek. Do URL se píší jen
 * hodnoty, které se liší od výchozích, ať adresa zůstane čitelná.
 */
export type InboxSort = 'delivered' | 'sender' | 'subject' | 'category'

export interface InboxFilters {
  q: string
  category: number | null
  sender: string | null
  type: InboxClassification | null
  direction: 'received' | 'sent' | null
  read: 'read' | 'unread' | null
  attachments: boolean
  sort: InboxSort
  order: 'asc' | 'desc'
  year: number | null
  month: number | null
  page: number
}

const CLASSIFICATIONS: InboxClassification[] = [
  'delivery_receipt', 'cssz_protocol', 'health_insurer_response', 'tax_office_response', 'unclassified',
]
const SORTS: InboxSort[] = ['delivered', 'sender', 'subject', 'category']

export function defaultSortOrder(sort: InboxSort): 'asc' | 'desc' {
  return sort === 'delivered' ? 'desc' : 'asc'
}

export function emptyInboxFilters(): InboxFilters {
  return {
    q: '',
    category: null,
    sender: null,
    type: null,
    direction: null,
    read: null,
    attachments: false,
    sort: 'delivered',
    order: 'desc',
    year: null,
    month: null,
    page: 1,
  }
}

type QueryValue = string | null | undefined | (string | null)[]

function first(value: QueryValue): string | null {
  const raw = Array.isArray(value) ? value[0] : value
  if (raw === null || raw === undefined) return null
  const trimmed = String(raw).trim()
  return trimmed === '' ? null : trimmed
}

function positiveInt(value: QueryValue, max = Number.MAX_SAFE_INTEGER): number | null {
  const raw = first(value)
  if (raw === null || !/^[1-9][0-9]*$/.test(raw)) return null
  const parsed = Number(raw)
  return parsed <= max ? parsed : null
}

/** Neznámé nebo poškozené hodnoty z URL se zahodí, ne pošlou serveru. */
export function parseInboxFilters(query: Record<string, QueryValue>): InboxFilters {
  const filters = emptyInboxFilters()
  filters.q = first(query.q) ?? ''
  filters.category = positiveInt(query.category)
  const sender = first(query.sender)?.toLowerCase() ?? null
  filters.sender = sender !== null && /^[a-z0-9]{7}$/.test(sender) ? sender : null
  const type = first(query.type)
  filters.type = type !== null && (CLASSIFICATIONS as string[]).includes(type) ? type as InboxClassification : null
  const direction = first(query.direction)
  filters.direction = direction === 'received' || direction === 'sent' ? direction : null
  const read = first(query.read)
  filters.read = read === 'read' || read === 'unread' ? read : null
  filters.attachments = first(query.attachments) === '1'
  const sort = first(query.sort)
  filters.sort = sort !== null && (SORTS as string[]).includes(sort) ? sort as InboxSort : 'delivered'
  const order = first(query.order)
  filters.order = order === 'asc' || order === 'desc' ? order : defaultSortOrder(filters.sort)
  const year = positiveInt(query.year, 2999)
  filters.year = year !== null && year >= 1900 ? year : null
  filters.month = positiveInt(query.month, 12)
  filters.page = positiveInt(query.page) ?? 1
  return filters
}

/** Zápis do URL — jen nevýchozí hodnoty. */
export function inboxFiltersToQuery(filters: InboxFilters): Record<string, string> {
  const query: Record<string, string> = {}
  if (filters.q.trim() !== '') query.q = filters.q.trim()
  if (filters.category !== null) query.category = String(filters.category)
  if (filters.sender !== null) query.sender = filters.sender
  if (filters.type !== null) query.type = filters.type
  if (filters.direction !== null) query.direction = filters.direction
  if (filters.read !== null) query.read = filters.read
  if (filters.attachments) query.attachments = '1'
  if (filters.sort !== 'delivered') query.sort = filters.sort
  if (filters.order !== defaultSortOrder(filters.sort)) query.order = filters.order
  if (filters.year !== null) query.year = String(filters.year)
  if (filters.month !== null) query.month = String(filters.month)
  if (filters.page > 1) query.page = String(filters.page)
  return query
}

/** Klíče, které filtry v URL obsazují — ostatní parametry stránky zůstanou. */
export const INBOX_QUERY_KEYS = [
  'q', 'category', 'sender', 'type', 'direction', 'read', 'attachments', 'sort', 'order', 'year', 'month', 'page',
] as const

export function inboxFiltersToApiParams(
  filters: InboxFilters,
  pageSize: number,
  visibility: 'active' | 'hidden',
): InboxBrowseParams {
  const params: InboxBrowseParams = {
    visibility,
    sort: filters.sort,
    order: filters.order,
    limit: pageSize,
    offset: (filters.page - 1) * pageSize,
  }
  if (filters.q.trim() !== '') params.q = filters.q.trim()
  if (filters.category !== null) params.category = filters.category
  if (filters.sender !== null) params.sender = filters.sender
  if (filters.type !== null) params.classification = filters.type
  if (filters.direction !== null) params.direction = filters.direction
  if (filters.read !== null) params.read = filters.read
  if (filters.attachments) params.attachments = '1'
  if (filters.year !== null) params.year = filters.year
  if (filters.month !== null) params.month = filters.month
  return params
}

/** Počet zapnutých filtrů pro odznak na tlačítku „Filtry" (bez hledání a řazení). */
export function activeInboxFilterCount(filters: InboxFilters): number {
  return [
    filters.sender !== null,
    filters.type !== null,
    filters.direction !== null,
    filters.read !== null,
    filters.attachments,
  ].filter(Boolean).length
}

/** Další krok řazení po kliknutí na hlavičku sloupce. */
export function toggleInboxSort(filters: InboxFilters, sort: InboxSort): Pick<InboxFilters, 'sort' | 'order'> {
  if (filters.sort !== sort) return { sort, order: defaultSortOrder(sort) }
  return { sort, order: filters.order === 'asc' ? 'desc' : 'asc' }
}
