import { describe, expect, it } from 'vitest'
import {
  activeInboxFilterCount,
  emptyInboxFilters,
  inboxFiltersToApiParams,
  inboxFiltersToQuery,
  parseInboxFilters,
  toggleInboxSort,
} from '@/utils/databoxInboxFilters'

describe('databoxInboxFilters', () => {
  it('výchozí stav nezapisuje do URL nic', () => {
    expect(inboxFiltersToQuery(emptyInboxFilters())).toEqual({})
  })

  it('URL → filtry → URL je beze ztráty', () => {
    const query = {
      q: 'platební výměr',
      category: '12',
      sender: 'abc1234',
      type: 'tax_office_response',
      direction: 'received',
      read: 'unread',
      attachments: '1',
      sort: 'sender',
      order: 'desc',
      year: '2026',
      month: '3',
      page: '2',
    }
    expect(inboxFiltersToQuery(parseInboxFilters(query))).toEqual(query)
  })

  it('poškozené hodnoty z URL zahodí', () => {
    const filters = parseInboxFilters({
      category: '0',
      sender: "x' OR 1",
      type: 'nonsense',
      direction: 'up',
      read: 'maybe',
      sort: 'id',
      order: 'sideways',
      year: 'abc',
      month: '0',
      page: '-3',
    })
    expect(filters).toEqual(emptyInboxFilters())
  })

  it('ID schránky převede na malá písmena, měsíc platí i bez roku', () => {
    const filters = parseInboxFilters({ sender: 'ABC1234', month: '4' })
    expect(filters.sender).toBe('abc1234')
    expect(filters.month).toBe(4)
    expect(parseInboxFilters({ month: '13' }).month).toBeNull()
  })

  it('parametry API počítají offset ze stránky a typ posílají jako classification', () => {
    const filters = { ...emptyInboxFilters(), type: 'cssz_protocol' as const, page: 3, q: '  protokol ' }
    expect(inboxFiltersToApiParams(filters, 25, 'hidden')).toEqual({
      visibility: 'hidden',
      sort: 'delivered',
      order: 'desc',
      limit: 25,
      offset: 50,
      q: 'protokol',
      classification: 'cssz_protocol',
    })
  })

  it('odznak počítá jen doplňkové filtry', () => {
    const filters = { ...emptyInboxFilters(), q: 'x', category: 1, sender: 'abc1234', read: 'unread' as const, attachments: true }
    expect(activeInboxFilterCount(filters)).toBe(3)
  })

  it('opakované kliknutí na sloupec obrací směr, nový sloupec začne výchozím směrem', () => {
    const filters = emptyInboxFilters()
    expect(toggleInboxSort(filters, 'delivered')).toEqual({ sort: 'delivered', order: 'asc' })
    expect(toggleInboxSort(filters, 'sender')).toEqual({ sort: 'sender', order: 'asc' })
    expect(toggleInboxSort({ ...filters, sort: 'sender', order: 'asc' }, 'sender')).toEqual({ sort: 'sender', order: 'desc' })
  })
})
